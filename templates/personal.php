<?php

script('twofactor_totp', 'settingsview');
script('twofactor_totp', 'settings');
style('twofactor_totp', 'personal');

?>

<div class="section" id="twofactor-totp-personal">
    <h2 class="app-name"><?php p($l->t('TOTP Second-factor Auth')); ?></h2>
    <div id="twofactor-totp-settings"></div>
</div>
