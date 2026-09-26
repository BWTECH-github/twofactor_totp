<?php
/**
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 *
 * Two-factor TOTP
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\TwoFactor_Totp\Repair;

use OCA\TwoFactor_Totp\Db\TotpSecretMapper;
use OCP\IConfig;
use OCP\ILogger;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Hält den zweiten Faktor von Konten aktiv, die ihn vor Version 0.4.4
 * eingerichtet haben.
 *
 * Bis 0.4.3 (ownCloud 9.1 und 10.0) gab es keine Bestätigung: Eine Zeile in
 * twofactor_totp_secrets hieß "TOTP ist aktiv". 0.4.4 hat die Spalte
 * "verified" mit Standard false eingeführt, und seitdem gilt nur ein
 * bestätigter Schlüssel. Beim Update legt der Schema-Abgleich die Spalte an und
 * setzt sie für alle vorhandenen Zeilen auf false - ohne diesen Schritt verlören
 * alle diese Konten ihren zweiten Faktor, ohne dass es jemand merkt.
 *
 * Der Schritt läuft nach dem Schema-Abgleich (repair-steps/post-migration). Der
 * Kern schreibt installed_version erst danach; solange sie vor 0.4.4 liegt,
 * stammen alle Zeilen aus der alten Fassung und werden als bestätigt markiert.
 * Das stellt genau das Verhalten von 0.4.3 wieder her: Konten mit Schlüssel
 * werden nach dem Code gefragt, sobald die App eingeschaltet ist.
 *
 * Ab 0.4.4 tut der Schritt nichts: Unbestätigte Schlüssel sind dort begonnene,
 * nie abgeschlossene Einrichtungen und bleiben unbestätigt. Nach dem Update
 * steht installed_version auf der neuen Fassung, ein zweiter Lauf ist damit ein
 * No-op. Bricht das Update nach diesem Schritt ab, markiert der nächste Lauf
 * dieselben alten Zeilen noch einmal - das Ergebnis bleibt gleich.
 */
class VerifyLegacySecrets implements IRepairStep {
	public const APP = 'twofactor_totp';

	/** Erste Fassung mit Bestätigung (Spalte "verified") */
	public const FIRST_VERSION_WITH_VERIFICATION = '0.4.4';

	/** @var IConfig */
	private $config;

	/** @var TotpSecretMapper */
	private $mapper;

	/** @var ILogger */
	private $logger;

	public function __construct(IConfig $config, TotpSecretMapper $mapper, ILogger $logger) {
		$this->config = $config;
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	public function getName() {
		return 'Keep two-factor authentication active for secrets set up before version 0.4.4';
	}

	public function run(IOutput $output) {
		$installed = (string)$this->config->getAppValue(self::APP, 'installed_version', '');
		if ($installed === ''
			|| \version_compare($installed, self::FIRST_VERSION_WITH_VERIFICATION, '>=')
		) {
			return;
		}

		$count = $this->mapper->markUnverifiedSecretsAsVerified();
		if ($count === 0) {
			return;
		}

		$message = \sprintf(
			'Updated from twofactor_totp %s, which had no verification step: %d existing TOTP secret(s) marked as verified,'
			. ' so these accounts keep their second factor. To turn it off for an account, use'
			. ' "occ twofactor_totp:set-secret-verification-status false --uid <uid>" or "occ twofactor_totp:delete-secret <uid>".',
			$installed,
			$count
		);
		$output->info($message);
		// Warnstufe, damit der Hinweis auch beim Standard-Loglevel 2 im
		// Serverprotokoll landet - die Übernahme soll nachvollziehbar sein.
		$this->logger->warning($message, ['app' => self::APP]);
	}
}
