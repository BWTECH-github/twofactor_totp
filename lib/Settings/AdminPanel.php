<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
 *
 * Two-factor TOTP
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 */

namespace OCA\TwoFactor_Totp\Settings;

use OCP\Settings\ISettings;
use OCP\Template;

class AdminPanel implements ISettings {
	/**
	 * @return Template
	 */
	public function getPanel() {
		\OCP\Util::addScript('twofactor_totp', 'admin');
		\OCP\Util::addStyle('twofactor_totp', 'admin');
		return new Template('twofactor_totp', 'admin');
	}

	/**
	 * @return string
	 */
	public function getSectionID() {
		return 'authentication';
	}

	/**
	 * @return int
	 */
	public function getPriority() {
		return 45;
	}
}
