/**
 * Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
 *
 * Redesign (1.0.0): TOTP-Verwaltung für Administratoren.
 * - Konten seitenweise über /admin/users, standardmäßig nur Konten mit TOTP
 * - Suche entprellt; eine überholte Antwort wird verworfen, nie angezeigt
 * - Namen, Konten und Adressen gelangen nur per textContent ins Dokument
 * - "Ausgewählte zurücksetzen" über den Kerndialog, "Alle zurücksetzen" erst
 *   nach Eingabe des Bestätigungsworts
 */
(function ($, OC, t, n) {
	'use strict';

	var APP = 'twofactor_totp';
	var PAGE_SIZE = 25;
	var SEARCH_DELAY = 300;
	var NAMES_IN_CONFIRMATION = 5;

	// Alle Texte landen per textContent im Dokument oder im Kerndialog, der
	// selbst maskiert - mit Maskierung durch t() stünde dort "&lt;".
	function tx(text, vars) {
		return t(APP, text, vars, undefined, {escape: false});
	}

	function nx(singular, plural, count, vars) {
		return n(APP, singular, plural, count, vars, {escape: false});
	}

	function loginName(account) {
		return account.userName || account.uid;
	}

	function accountName(account) {
		var login = loginName(account);
		var name = account.displayName || login;
		return name === login ? login : name + ' (' + login + ')';
	}

	// Rollen ausdrücklich: in schmalen Behältern stapelt das Stylesheet die
	// Zellen, und manche Browser verwerfen dabei sonst die Tabellensemantik.
	function cell(tag, role, className) {
		var element = document.createElement(tag);
		element.setAttribute('role', role);
		element.className = className;
		return element;
	}

	function normalizeWord(value) {
		return String(value).trim().normalize('NFC').toUpperCase();
	}

	function TotpAdmin(root) {
		this.root = root;
		this.confirmWord = root.getAttribute('data-confirm-word') || 'RESET';
		this.search = root.querySelector('#totp-admin-search');
		this.onlyTotp = root.querySelector('#totp-admin-only-totp');
		this.list = root.querySelector('.totp-admin-list');
		this.tbody = root.querySelector('#totp-admin-table tbody');
		this.selectAll = root.querySelector('#totp-admin-select-all');
		this.state = root.querySelector('#totp-admin-state');
		this.more = root.querySelector('.totp-admin-more');
		this.retry = root.querySelector('.totp-admin-retry');
		this.resetSelected = root.querySelector('#totp-admin-reset-selected');
		this.count = root.querySelector('#totp-admin-selected-count');
		this.status = root.querySelector('#totp-admin-status');
		this.alert = root.querySelector('#totp-admin-alert');
		this.dangerCount = root.querySelector('#totp-admin-danger-count');
		this.resetAllStart = root.querySelector('#totp-admin-reset-all-start');
		this.confirmBox = root.querySelector('#totp-admin-confirm-all');
		this.confirmInput = root.querySelector('#totp-admin-confirm-input');
		this.resetAll = root.querySelector('#totp-admin-reset-all');
		this.resetAllCancel = root.querySelector('#totp-admin-reset-all-cancel');

		// Ohne Prototyp: Kennungen wie "__proto__" oder "hasOwnProperty" sind
		// gültige Konten und dürfen die Zuordnung nicht verbiegen.
		this.accounts = Object.create(null);
		this.selected = Object.create(null);
		this.nextOffset = null;
		this.totpCount = 0;
		this.request = null;
		this.requestNumber = 0;
		this.lastLoad = null;
		this.lastQuery = '';
		this.searchTimer = null;
		this.busy = false;
		this.rowNumber = 0;
	}

	TotpAdmin.prototype.start = function () {
		var self = this;

		this.search.addEventListener('input', function () {
			window.clearTimeout(self.searchTimer);
			self.searchTimer = window.setTimeout(function () {
				self.load({reset: true, announce: true});
			}, SEARCH_DELAY);
		});
		this.search.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				window.clearTimeout(self.searchTimer);
				self.load({reset: true, announce: true});
			}
		});
		this.onlyTotp.addEventListener('change', function () {
			self.load({reset: true, announce: true});
		});
		this.more.addEventListener('click', function () {
			if (self.nextOffset !== null) {
				self.load({reset: false, announce: true, fromMore: true});
			}
		});
		this.retry.addEventListener('click', function () {
			self.load(self.lastLoad || {reset: true, announce: true});
		});

		this.selectAll.addEventListener('change', function () {
			self.selectAllShown(self.selectAll.checked);
		});
		this.tbody.addEventListener('change', function (event) {
			var box = event.target;
			if (box.matches('input[type="checkbox"]')) {
				self.setSelected(box.closest('tr'), box.checked);
				self.updateSelection();
			}
		});
		this.tbody.addEventListener('click', function (event) {
			self.toggleRowFromClick(event);
		});

		this.resetSelected.addEventListener('click', function () {
			self.confirmResetSelected();
		});
		this.resetAllStart.addEventListener('click', function () {
			self.toggleConfirmAll();
		});
		this.confirmInput.addEventListener('input', function () {
			self.updateConfirmAll();
		});
		this.confirmInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				self.runResetAll();
			}
		});
		this.confirmBox.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				self.closeConfirmAll(true);
			}
		});
		this.resetAll.addEventListener('click', function () {
			self.runResetAll();
		});
		this.resetAllCancel.addEventListener('click', function () {
			self.closeConfirmAll(true);
		});

		this.updateSelection();
		this.updateDangerZone();
		this.load({reset: true, announce: false});
	};

	/**
	 * @param {{reset: boolean, announce: boolean, fromMore: (boolean|undefined)}} options
	 */
	TotpAdmin.prototype.load = function (options) {
		var self = this;
		var number = ++this.requestNumber;
		if (this.request) {
			this.request.abort();
		}
		this.lastLoad = options;
		if (options.reset) {
			this.lastQuery = this.search.value.trim();
			// Sonst hinge "Weitere Konten laden" Treffer der neuen Suche an die
			// Zeilen der alten, solange die neue noch lädt.
			this.nextOffset = null;
			this.more.hidden = true;
		}
		this.list.setAttribute('aria-busy', 'true');
		this.retry.hidden = true;
		this.state.textContent = tx('Loading accounts …');

		this.request = $.ajax({
			type: 'GET',
			url: OC.generateUrl('/apps/twofactor_totp/admin/users'),
			dataType: 'json',
			data: {
				query: this.lastQuery,
				limit: PAGE_SIZE,
				offset: options.reset ? 0 : this.nextOffset,
				onlyTotp: this.onlyTotp.checked ? 1 : 0
			}
		});
		this.request.done(function (data) {
			if (number === self.requestNumber) {
				self.request = null;
				self.render(data || {}, options);
			}
		}).fail(function (xhr, textStatus) {
			// Abgebrochen wird nur zugunsten einer neueren Anfrage.
			if (number === self.requestNumber && textStatus !== 'abort') {
				self.request = null;
				self.showLoadError(options.reset);
			}
		});
	};

	TotpAdmin.prototype.render = function (data, options) {
		var users = Array.isArray(data.users) ? data.users : [];
		var fragment = document.createDocumentFragment();
		var firstNew = null;
		var added = 0;

		if (options.reset) {
			this.tbody.textContent = '';
			this.accounts = Object.create(null);
		}
		users.forEach(function (account) {
			// Beim Blättern kann ein Konto erneut kommen, wenn sich die Liste
			// zwischen zwei Seiten verschoben hat - es bleibt einmal stehen.
			if (!account || typeof account.uid !== 'string' || account.uid in this.accounts) {
				return;
			}
			this.accounts[account.uid] = account;
			var row = this.buildRow(account);
			fragment.appendChild(row);
			firstNew = firstNew || row;
			added++;
		}, this);
		this.tbody.appendChild(fragment);

		if (options.reset) {
			// Ausgewählt bleibt nur, was weiterhin angezeigt wird.
			var kept = Object.create(null);
			Object.keys(this.selected).forEach(function (uid) {
				var account = this.accounts[uid];
				if (account && account.hasTotp === true) {
					kept[uid] = true;
				}
			}, this);
			this.selected = kept;
			this.tbody.querySelectorAll('tr').forEach(function (row) {
				var on = row.dataset.uid in this.selected;
				row.querySelector('input[type="checkbox"]').checked = on;
				row.classList.toggle('totp-admin-row--selected', on);
			}, this);
		}

		this.nextOffset = data.hasMore && typeof data.nextOffset === 'number' ? data.nextOffset : null;
		if (typeof data.totpCount === 'number') {
			this.totpCount = data.totpCount;
		}
		this.more.hidden = this.nextOffset === null;
		this.list.setAttribute('aria-busy', 'false');
		this.state.textContent = this.rowCount() === 0 ? this.emptyText() : '';
		this.updateSelection();
		this.updateDangerZone();

		if (options.announce) {
			this.say(options.reset
				? this.resultText(data)
				: nx('%n more account loaded', '%n more accounts loaded', added));
		}
		// Ist "Weitere Konten laden" verschwunden, ginge der Fokus verloren.
		if (options.fromMore && this.more.hidden && firstNew) {
			firstNew.focus();
		}
	};

	TotpAdmin.prototype.buildRow = function (account) {
		var selectable = account.hasTotp === true;
		var id = 'totp-admin-pick-' + (++this.rowNumber);
		var row = document.createElement('tr');
		row.setAttribute('role', 'row');
		row.dataset.uid = account.uid;
		row.tabIndex = -1;
		if (!selectable) {
			row.classList.add('totp-admin-row--locked');
		}

		var pickCell = cell('td', 'cell', 'totp-admin-col-select');
		var box = document.createElement('input');
		box.type = 'checkbox';
		box.className = 'checkbox';
		box.id = id;
		box.disabled = !selectable;
		var label = document.createElement('label');
		label.htmlFor = id;
		var labelText = document.createElement('span');
		labelText.className = 'hidden-visually';
		labelText.textContent = tx('Select {name}', {name: accountName(account)});
		label.appendChild(labelText);
		pickCell.appendChild(box);
		pickCell.appendChild(label);

		var nameCell = cell('th', 'rowheader', 'totp-admin-col-name');
		nameCell.scope = 'row';
		var name = document.createElement('span');
		name.className = 'totp-admin-name';
		name.textContent = account.displayName || loginName(account);
		nameCell.appendChild(name);
		if (account.enabled === false) {
			var disabled = document.createElement('span');
			disabled.className = 'oco-chip oco-chip--neutral totp-admin-chip-disabled';
			disabled.textContent = tx('Account disabled');
			nameCell.appendChild(disabled);
		}

		var accountCell = cell('td', 'cell', 'totp-admin-col-account');
		accountCell.textContent = loginName(account);
		if (loginName(account) !== account.uid) {
			var uid = document.createElement('span');
			uid.className = 'totp-admin-uid';
			uid.textContent = tx('ID: {uid}', {uid: account.uid});
			accountCell.appendChild(uid);
		}

		var mailCell = cell('td', 'cell', 'totp-admin-col-mail');
		if (account.email) {
			mailCell.textContent = account.email;
		} else {
			var dash = document.createElement('span');
			dash.setAttribute('aria-hidden', 'true');
			dash.textContent = '–';
			var none = document.createElement('span');
			none.className = 'hidden-visually';
			none.textContent = tx('No email address');
			mailCell.appendChild(dash);
			mailCell.appendChild(none);
		}

		var statusCell = cell('td', 'cell', 'totp-admin-col-status');
		var chip = document.createElement('span');
		if (account.totpVerified === true) {
			chip.className = 'oco-chip oco-chip--an totp-admin-status-chip';
			chip.textContent = tx('TOTP set up');
		} else if (selectable) {
			chip.className = 'oco-chip totp-admin-chip--pending totp-admin-status-chip';
			chip.textContent = tx('Setup not finished');
		} else {
			chip.className = 'oco-chip oco-chip--aus totp-admin-status-chip';
			chip.textContent = tx('Not set up');
		}
		statusCell.appendChild(chip);

		row.appendChild(pickCell);
		row.appendChild(nameCell);
		row.appendChild(accountCell);
		row.appendChild(mailCell);
		row.appendChild(statusCell);
		return row;
	};

	TotpAdmin.prototype.rowCount = function () {
		return this.tbody.rows.length;
	};

	TotpAdmin.prototype.emptyText = function () {
		if (this.lastQuery !== '') {
			return tx('No account matches “{query}”.', {query: this.lastQuery});
		}
		return this.onlyTotp.checked ? tx('No account has TOTP set up.') : tx('No accounts found.');
	};

	TotpAdmin.prototype.resultText = function (data) {
		var shown = this.rowCount();
		if (shown === 0) {
			return this.emptyText();
		}
		if (typeof data.total === 'number') {
			return nx('%n account found', '%n accounts found', data.total);
		}
		if (this.nextOffset !== null) {
			return tx('{count} accounts shown, more can be loaded.', {count: shown});
		}
		return nx('%n account found', '%n accounts found', shown);
	};

	TotpAdmin.prototype.showLoadError = function (reset) {
		if (reset) {
			// Die alten Zeilen gehörten zur vorigen Suche.
			this.tbody.textContent = '';
			this.accounts = Object.create(null);
			this.selected = Object.create(null);
			this.updateSelection();
		}
		this.list.setAttribute('aria-busy', 'false');
		this.state.textContent = tx('Accounts could not be loaded.');
		this.retry.hidden = false;
		this.shout(tx('Accounts could not be loaded.'));
	};

	TotpAdmin.prototype.toggleRowFromClick = function (event) {
		if (event.target.closest('input, label, a, button')) {
			return;
		}
		var row = event.target.closest('tr');
		var box = row && row.querySelector('input[type="checkbox"]');
		if (!box || box.disabled) {
			return;
		}
		// Wer Text markiert (etwa eine Adresse zum Kopieren), will nicht auswählen.
		if (window.getSelection && String(window.getSelection()) !== '') {
			return;
		}
		box.checked = !box.checked;
		this.setSelected(row, box.checked);
		this.updateSelection();
	};

	TotpAdmin.prototype.setSelected = function (row, on) {
		if (!row) {
			return;
		}
		if (on) {
			this.selected[row.dataset.uid] = true;
		} else {
			delete this.selected[row.dataset.uid];
		}
		row.classList.toggle('totp-admin-row--selected', on);
	};

	TotpAdmin.prototype.selectAllShown = function (on) {
		this.tbody.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach(function (box) {
			box.checked = on;
			this.setSelected(box.closest('tr'), on);
		}, this);
		this.updateSelection();
	};

	TotpAdmin.prototype.updateSelection = function () {
		var count = Object.keys(this.selected).length;
		var boxes = this.tbody.querySelectorAll('input[type="checkbox"]:not(:disabled)');
		var checked = 0;
		boxes.forEach(function (box) {
			checked += box.checked ? 1 : 0;
		});
		this.count.textContent = tx('{count} selected', {count: count});
		this.resetSelected.setAttribute('aria-disabled', count === 0 || this.busy ? 'true' : 'false');
		this.selectAll.disabled = boxes.length === 0;
		this.selectAll.checked = boxes.length > 0 && checked === boxes.length;
		this.selectAll.indeterminate = checked > 0 && checked < boxes.length;
	};

	TotpAdmin.prototype.confirmResetSelected = function () {
		var self = this;
		var uids = Object.keys(this.selected);
		if (this.busy) {
			return;
		}
		if (uids.length === 0) {
			this.say(tx('Select at least one account first.'));
			return;
		}
		var names = uids.slice(0, NAMES_IN_CONFIRMATION).map(function (uid) {
			return accountName(this.accounts[uid]);
		}, this).join(', ');
		if (uids.length > NAMES_IN_CONFIRMATION) {
			names += ' ' + tx('and {count} more', {count: uids.length - NAMES_IN_CONFIRMATION});
		}
		OC.dialogs.confirm(
			nx('Reset TOTP for %n account: {names}? Its previous authenticator app then no longer works.',
				'Reset TOTP for %n accounts: {names}? Their previous authenticator apps then no longer work.',
				uids.length, {names: names}),
			tx('Reset TOTP?'),
			function (confirmed) {
				if (confirmed) {
					self.runResetSelected(uids);
				}
			},
			true,
			tx('Cancel'),
			tx('Reset TOTP')
		);
	};

	TotpAdmin.prototype.runResetSelected = function (uids) {
		var self = this;
		this.setBusy(true);
		this.say(tx('Resetting TOTP …'));
		$.ajax({
			type: 'POST',
			url: OC.generateUrl('/apps/twofactor_totp/admin/reset-users'),
			dataType: 'json',
			data: {uids: uids}
		}).done(function (data) {
			var results = Array.isArray(data && data.results) ? data.results : [];
			var done = results.filter(function (result) {
				return result.status === 'success';
			}).length;
			var missing = results.filter(function (result) {
				return result.status === 'missing';
			}).length;
			var text = nx('TOTP reset for %n account.', 'TOTP reset for %n accounts.', done);
			if (missing > 0) {
				text += ' ' + nx('%n account no longer exists.', '%n accounts no longer exist.', missing);
			}
			self.selected = Object.create(null);
			self.say(text);
			self.load({reset: true, announce: false});
		}).fail(function () {
			self.shout(tx('TOTP could not be reset.'));
		}).always(function () {
			self.setBusy(false);
		});
	};

	TotpAdmin.prototype.updateDangerZone = function () {
		var nothingToReset = this.totpCount === 0;
		this.dangerCount.textContent = nothingToReset
			? tx('No account has TOTP set up.')
			: nx('Currently affects %n account.', 'Currently affects %n accounts.', this.totpCount);
		this.resetAllStart.setAttribute('aria-disabled', nothingToReset || this.busy ? 'true' : 'false');
		if (nothingToReset && !this.confirmBox.hidden) {
			this.closeConfirmAll(this.confirmBox.contains(document.activeElement));
		}
		this.updateConfirmAll();
	};

	TotpAdmin.prototype.toggleConfirmAll = function () {
		if (this.busy) {
			return;
		}
		if (this.totpCount === 0) {
			this.say(tx('No account has TOTP set up.'));
			return;
		}
		if (this.confirmBox.hidden) {
			this.confirmBox.hidden = false;
			this.resetAllStart.setAttribute('aria-expanded', 'true');
			this.confirmInput.value = '';
			this.updateConfirmAll();
			this.confirmInput.focus();
		} else {
			this.closeConfirmAll(true);
		}
	};

	TotpAdmin.prototype.closeConfirmAll = function (returnFocus) {
		this.confirmBox.hidden = true;
		this.resetAllStart.setAttribute('aria-expanded', 'false');
		this.confirmInput.value = '';
		this.updateConfirmAll();
		if (returnFocus) {
			this.resetAllStart.focus();
		}
	};

	TotpAdmin.prototype.confirmWordTyped = function () {
		return normalizeWord(this.confirmInput.value) === normalizeWord(this.confirmWord);
	};

	TotpAdmin.prototype.updateConfirmAll = function () {
		this.resetAll.setAttribute('aria-disabled', this.confirmWordTyped() && !this.busy ? 'false' : 'true');
	};

	TotpAdmin.prototype.runResetAll = function () {
		var self = this;
		if (this.busy) {
			return;
		}
		if (!this.confirmWordTyped()) {
			this.say(tx('Type %s to confirm').replace('%s', this.confirmWord));
			this.confirmInput.focus();
			return;
		}
		this.setBusy(true);
		this.say(tx('Resetting all TOTP setups …'));
		$.ajax({
			type: 'POST',
			url: OC.generateUrl('/apps/twofactor_totp/admin/reset-all'),
			dataType: 'json'
		}).done(function () {
			self.closeConfirmAll(true);
			self.selected = Object.create(null);
			self.say(tx('All TOTP setups have been reset.'));
			self.load({reset: true, announce: false});
		}).fail(function () {
			self.shout(tx('TOTP setups could not be reset.'));
		}).always(function () {
			self.setBusy(false);
		});
	};

	TotpAdmin.prototype.setBusy = function (busy) {
		this.busy = busy;
		this.updateSelection();
		this.updateDangerZone();
	};

	TotpAdmin.prototype.say = function (text) {
		this.alert.textContent = '';
		this.status.textContent = text;
	};

	TotpAdmin.prototype.shout = function (text) {
		this.status.textContent = '';
		this.alert.textContent = text;
	};

	$(function () {
		var root = document.getElementById('twofactor-totp-admin');
		if (root) {
			new TotpAdmin(root).start();
		}
	});
})(jQuery, OC, t, n);
