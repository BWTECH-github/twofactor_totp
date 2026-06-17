<?php
/**
 * modified by BW-Tech GmbH
 */

script('core', 'login');
?>

<?php if(!$_['isConfigured']): ?>
<div class="grouptop" style="align-items:center;">
	<p class="info"><?php p($l->t('Scan the QR code below with your TOTP app and enter the code')); ?></p>
	<img src="<?php p($_['qr']); ?>" alt="<?php p($l->t('QR code for TOTP authenticator setup')); ?>" />
	<?php if(!empty($_['secret'])): ?>
		<p class="info"><?php p($l->t('This is your new TOTP secret:')); ?></p>
		<code style="display:block; padding:10px; word-break:break-all; background:rgba(255,255,255,.85); color:#1f2933; border-radius:6px;"><?php p($_['secret']); ?></code>
	<?php endif; ?>
</div>
<?php endif; ?>
<form method="POST" name="login">
	<div class="grouptop">
		<label for="challenge" class="hidden-visually"><?php p($l->t('Authentication code')); ?></label>
		<input type="text" id="challenge" name="challenge" required="required" autofocus autocomplete="off" autocapitalize="off">
	</div>
    <div class="submit-wrap">
        <button type="submit" id="submit" class="login-button">
            <span><?php p($l->t('Verify')); ?></span>
			<div class="loading-spinner"><div></div><div></div><div></div><div></div></div>
        </button>
    </div>
</form>
