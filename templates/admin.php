<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
 *
 * Redesign (1.0.0): Konten als Tabelle mit Kästchen statt Mehrfachauswahl,
 * Suche mit TOTP-Filter, "Alle zurücksetzen" als abgesetzter Gefahrenbereich
 * mit Bestätigungswort. Die Zeilen baut js/admin.js; Namen und Adressen
 * gelangen dort nur als Text ins Dokument.
 *
 * @var \OCP\IL10N $l
 */
$confirmWord = $l->t('RESET');
?>
<div class="section" id="twofactor-totp-admin" data-confirm-word="<?php p($confirmWord); ?>">
	<h2><?php p($l->t('Reset two-factor sign-in (TOTP)')); ?></h2>
	<p class="totp-admin-intro"><?php p($l->t('After a lost device, reset the TOTP setup of an account here. The previous authenticator app then no longer works; until TOTP is set up again, the account signs in without a second factor unless two-factor sign-in is mandatory.')); ?></p>

	<div class="totp-admin-toolbar">
		<div class="totp-admin-search">
			<label for="totp-admin-search"><?php p($l->t('Search accounts')); ?></label>
			<input type="search" id="totp-admin-search" autocomplete="off" spellcheck="false"
				placeholder="<?php p($l->t('Name, user name or email')); ?>"
				aria-controls="totp-admin-table">
		</div>
		<div class="totp-admin-filter">
			<input type="checkbox" class="checkbox" id="totp-admin-only-totp" checked="checked"
				aria-controls="totp-admin-table">
			<label for="totp-admin-only-totp"><?php p($l->t('Only accounts with TOTP')); ?></label>
		</div>
	</div>

	<div class="totp-admin-list" aria-busy="false">
		<?php /* Die Rollen sind doppelt gemoppelt, solange die Tabelle eine Tabelle
			ist; in schmalen Behältern stapelt css/admin.css die Zellen (display:
			grid/block), und dabei verwerfen manche Browser sonst die Semantik. */ ?>
		<table id="totp-admin-table" class="totp-admin-table" role="table">
			<caption class="hidden-visually"><?php p($l->t('Accounts')); ?></caption>
			<thead role="rowgroup">
				<tr role="row">
					<th scope="col" role="columnheader" class="totp-admin-col-select">
						<input type="checkbox" class="checkbox" id="totp-admin-select-all" disabled="disabled">
						<label for="totp-admin-select-all"><span class="totp-admin-select-all-text"><?php p($l->t('Select all shown accounts')); ?></span></label>
					</th>
					<th scope="col" role="columnheader"><?php p($l->t('Display name')); ?></th>
					<th scope="col" role="columnheader"><?php p($l->t('Account')); ?></th>
					<th scope="col" role="columnheader"><?php p($l->t('Email')); ?></th>
					<th scope="col" role="columnheader"><?php p($l->t('Status')); ?></th>
				</tr>
			</thead>
			<tbody role="rowgroup"></tbody>
		</table>
		<p id="totp-admin-state" class="totp-admin-state"></p>
		<div class="totp-admin-list-buttons">
			<button type="button" class="totp-admin-more" hidden><?php p($l->t('Load more accounts')); ?></button>
			<button type="button" class="totp-admin-retry" hidden><?php p($l->t('Try again')); ?></button>
		</div>
	</div>

	<div class="totp-admin-actions">
		<button type="button" id="totp-admin-reset-selected" class="oco-btn-primary"
			aria-disabled="true" aria-describedby="totp-admin-selected-count"><?php p($l->t('Reset selected')); ?></button>
		<span id="totp-admin-selected-count" class="totp-admin-count"></span>
	</div>
	<p id="totp-admin-status" class="totp-admin-message" role="status"></p>
	<p id="totp-admin-alert" class="totp-admin-message totp-admin-message--error" role="alert"></p>

	<div class="totp-admin-danger" role="group" aria-labelledby="totp-admin-danger-title">
		<h3 id="totp-admin-danger-title"><?php p($l->t('Reset all TOTP setups')); ?></h3>
		<p><?php p($l->t('Removes TOTP from all accounts at once. Only meant for emergencies, for example when the stored secrets may have been exposed.')); ?></p>
		<p id="totp-admin-danger-count" class="totp-admin-danger-count"></p>
		<button type="button" id="totp-admin-reset-all-start" class="oco-btn-danger"
			aria-expanded="false" aria-controls="totp-admin-confirm-all"
			aria-describedby="totp-admin-danger-count"><?php p($l->t('Reset all …')); ?></button>
		<div id="totp-admin-confirm-all" class="totp-admin-confirm-all" hidden>
			<label for="totp-admin-confirm-input"><?php p($l->t('Type %s to confirm', [$confirmWord])); ?></label>
			<div class="totp-admin-confirm-row">
				<input type="text" id="totp-admin-confirm-input" autocomplete="off" spellcheck="false"
					autocapitalize="characters" aria-describedby="totp-admin-danger-count">
				<button type="button" id="totp-admin-reset-all" class="oco-btn-danger"
					aria-disabled="true"><?php p($l->t('Reset all now')); ?></button>
				<button type="button" id="totp-admin-reset-all-cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</div>
	</div>
</div>
