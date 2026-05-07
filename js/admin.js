/**
 * Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
 */
(function ($, OC) {
	$(document).ready(function () {
		var $section = $('#twofactor-totp-admin');
		if ($section.length === 0) {
			return;
		}

		var $searchInput = $('#totp-admin-user-search');
		var $searchButton = $('#totp-admin-search-button');
		var $userSelect = $('#totp-admin-user-select');
		var $resetSelectedButton = $('#totp-admin-reset-selected-button');
		var $resetAllButton = $('#totp-admin-reset-all-button');
		var $message = $('#totp-admin-reset-message');
		var searchTimer = null;

		function showMessage(text, isError) {
			$message
				.text(text)
				.toggleClass('error', isError)
				.toggleClass('success', !isError);
		}

		function setBusy(isBusy) {
			$searchButton.prop('disabled', isBusy);
			$resetSelectedButton.prop('disabled', isBusy);
			$resetAllButton.prop('disabled', isBusy);
			$userSelect.prop('disabled', isBusy);
		}

		function formatUser(user) {
			var label = user.uid;
			if (user.userName && user.userName !== user.uid) {
				label += ' | ' + user.userName;
			}
			if (user.displayName && user.displayName !== user.uid && user.displayName !== user.userName) {
				label += ' | ' + user.displayName;
			}
			if (user.email) {
				label += ' | ' + user.email;
			}
			if (user.hasTotp) {
				label += ' | ' + t('twofactor_totp', 'TOTP active');
			}
			if (!user.enabled) {
				label += ' | ' + t('twofactor_totp', 'disabled');
			}
			return label;
		}

		function renderUsers(users) {
			$userSelect.empty();
			if (users.length === 0) {
				$('<option>')
					.prop('disabled', true)
					.text(t('twofactor_totp', 'No users found'))
					.appendTo($userSelect);
				return;
			}

			$.each(users, function (_, user) {
				$('<option>')
					.val(user.uid)
					.text(formatUser(user))
					.appendTo($userSelect);
			});
		}

		function loadUsers(silent) {
			setBusy(true);
			$.ajax({
				type: 'GET',
				url: OC.generateUrl('/apps/twofactor_totp/admin/users'),
				data: {
					query: $.trim($searchInput.val()),
					limit: 5000
				}
			}).done(function (response) {
				renderUsers(response.users || []);
				if (!silent) {
					showMessage(t('twofactor_totp', '{count} users loaded.', { count: (response.users || []).length }), false);
				}
			}).fail(function (xhr) {
				var message = t('twofactor_totp', 'Could not load users.');
				if (xhr.responseJSON && xhr.responseJSON.message) {
					message = xhr.responseJSON.message;
				}
				showMessage(message, true);
			}).always(function () {
				setBusy(false);
			});
		}

		function selectedUserIds() {
			return $userSelect.val() || [];
		}

		function resetSelected() {
			var uids = selectedUserIds();
			if (uids.length === 0) {
				showMessage(t('twofactor_totp', 'Select at least one user.'), true);
				return;
			}

			setBusy(true);
			showMessage(t('twofactor_totp', 'Resetting TOTP...'), false);
			$.ajax({
				type: 'POST',
				url: OC.generateUrl('/apps/twofactor_totp/admin/reset-users'),
				data: {
					uids: uids
				}
			}).done(function (response) {
				showMessage(t('twofactor_totp', 'TOTP reset for {count} selected users. Deleted secrets: {deleted}.', {
					count: uids.length,
					deleted: response.deleted
				}), false);
				loadUsers(true);
			}).fail(function (xhr) {
				var message = t('twofactor_totp', 'Could not reset selected users.');
				if (xhr.responseJSON && xhr.responseJSON.message) {
					message = xhr.responseJSON.message;
				}
				showMessage(message, true);
			}).always(function () {
				setBusy(false);
			});
		}

		function resetAll() {
			if (!window.confirm(t('twofactor_totp', 'Reset TOTP for all users? Every user with TOTP must configure a new authenticator.'))) {
				return;
			}

			setBusy(true);
			showMessage(t('twofactor_totp', 'Resetting all TOTP setups...'), false);
			$.ajax({
				type: 'POST',
				url: OC.generateUrl('/apps/twofactor_totp/admin/reset-all')
			}).done(function (response) {
				showMessage(t('twofactor_totp', 'All TOTP setups reset. Deleted secrets: {deleted}.', {
					deleted: response.deleted
				}), false);
				loadUsers(true);
			}).fail(function (xhr) {
				var message = t('twofactor_totp', 'Could not reset all TOTP setups.');
				if (xhr.responseJSON && xhr.responseJSON.message) {
					message = xhr.responseJSON.message;
				}
				showMessage(message, true);
			}).always(function () {
				setBusy(false);
			});
		}

		$searchButton.on('click', loadUsers);
		$searchInput.on('input', function () {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(loadUsers, 250);
		});
		$resetSelectedButton.on('click', resetSelected);
		$resetAllButton.on('click', resetAll);

		loadUsers();
	});
})(jQuery, OC);
