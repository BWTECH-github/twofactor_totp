<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
 */
?>
<div class="section" id="twofactor-totp-admin">
	<h2><?php p($l->t('TOTP Admin Reset')); ?></h2>
	<p><?php p($l->t('Reset a user TOTP setup after device loss. The user must configure a new authenticator on the next two-factor login.')); ?></p>
	<div class="totp-admin-user-picker">
		<p>
			<label for="totp-admin-user-search"><?php p($l->t('Search users')); ?></label>
			<input type="search" id="totp-admin-user-search" name="totp-admin-user-search" placeholder="<?php p($l->t('User ID, username, display name or email')); ?>" autocomplete="off" autocorrect="off" />
			<button type="button" id="totp-admin-search-button" class="button"><?php p($l->t('Search')); ?></button>
		</p>
		<p>
			<label for="totp-admin-user-select"><?php p($l->t('Users')); ?></label>
			<select id="totp-admin-user-select" name="totp-admin-user-select" multiple="multiple" size="20"></select>
		</p>
		<p>
			<button type="button" id="totp-admin-reset-selected-button" class="button"><?php p($l->t('Reset selected')); ?></button>
			<button type="button" id="totp-admin-reset-all-button" class="button"><?php p($l->t('Reset all TOTP setups')); ?></button>
			<span id="totp-admin-reset-message" class="msg"></span>
		</p>
	</div>
</div>
