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

namespace OCA\TwoFactor_Totp\Tests\Repair;

use OCA\TwoFactor_Totp\Db\TotpSecretMapper;
use OCA\TwoFactor_Totp\Repair\VerifyLegacySecrets;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Prüft gegen die echte Tabelle twofactor_totp_secrets, dass Schlüssel aus
 * Fassungen ohne Bestätigung (bis 0.4.3) nach dem Update aktiv bleiben - so
 * wie sie nach dem Schema-Abgleich vorliegen: Spalte "verified" neu, Wert
 * false.
 *
 * @group DB
 */
class VerifyLegacySecretsTest extends TestCase {
	/** @var IDBConnection */
	private $db;

	/** @var IConfig */
	private $config;

	/** @var string|null */
	private $installedVersionBefore;

	protected function setUp(): void {
		parent::setUp();
		$this->db = \OC::$server->getDatabaseConnection();
		$this->config = \OC::$server->getConfig();
		$this->installedVersionBefore = $this->config->getAppValue('twofactor_totp', 'installed_version', null);
		$this->db->getQueryBuilder()->delete('twofactor_totp_secrets')->execute();
	}

	protected function tearDown(): void {
		$this->db->getQueryBuilder()->delete('twofactor_totp_secrets')->execute();
		if ($this->installedVersionBefore === null) {
			$this->config->deleteAppValue('twofactor_totp', 'installed_version');
		} else {
			$this->config->setAppValue('twofactor_totp', 'installed_version', $this->installedVersionBefore);
		}
		parent::tearDown();
	}

	/**
	 * Zeile so anlegen, wie sie nach dem Schema-Abgleich aussieht. NULL steht
	 * für eine Datenbank, die die neue Spalte nicht mit ihrem Standard gefüllt
	 * hat - die Spalte ist nicht NOT NULL.
	 *
	 * @param string $uid
	 * @param bool|null $verified
	 */
	private function insertSecret(string $uid, ?bool $verified): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('twofactor_totp_secrets')->values([
			'user_id' => $qb->createNamedParameter($uid),
			'secret' => $qb->createNamedParameter('encrypted-secret-of-' . $uid),
			'verified' => $verified === null
				? $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL)
				: $qb->createNamedParameter($verified, IQueryBuilder::PARAM_BOOL),
		])->execute();
	}

	/**
	 * @return array<string, bool|null> user_id => verified
	 */
	private function verifiedByUser(): array {
		$result = [];
		$mapper = new TotpSecretMapper($this->db);
		foreach ($mapper->getAllSecrets() as $row) {
			$result[$row['user_id']] = $row['verified'] === null ? null : (bool)$row['verified'];
		}
		\ksort($result);
		return $result;
	}

	private function setInstalledVersion(?string $version): void {
		if ($version === null) {
			$this->config->deleteAppValue('twofactor_totp', 'installed_version');
		} else {
			$this->config->setAppValue('twofactor_totp', 'installed_version', $version);
		}
	}

	private function runStep(?ILogger $logger = null): void {
		$step = new VerifyLegacySecrets(
			$this->config,
			new TotpSecretMapper($this->db),
			$logger ?? $this->createMock(ILogger::class)
		);
		$step->run($this->createMock(IOutput::class));
	}

	public function legacyVersions(): array {
		return [
			'ownCloud 9.1' => ['0.4.2'],
			'ownCloud 10.0' => ['0.4.3'],
			'sehr alt' => ['0.3'],
		];
	}

	/**
	 * @dataProvider legacyVersions
	 */
	public function testKeepsSecondFactorOfAccountsFromVersionsWithoutVerification(string $version): void {
		$this->setInstalledVersion($version);
		$this->insertSecret('alice', false);
		$this->insertSecret('bob', null);

		$this->runStep();

		$this->assertSame(['alice' => true, 'bob' => true], $this->verifiedByUser());
	}

	public function newerVersions(): array {
		return [
			'erste Fassung mit Bestätigung' => ['0.4.4'],
			'ownCloud 10.1' => ['0.5.1'],
			'eigene Fassung' => ['0.10.3'],
		];
	}

	/**
	 * @dataProvider newerVersions
	 */
	public function testLeavesUnconfirmedSetupsOfNewerVersionsUnverified(string $version): void {
		// Ab 0.4.4 heißt "unbestätigt": Einrichtung begonnen, nie abgeschlossen.
		$this->setInstalledVersion($version);
		$this->insertSecret('alice', false);
		$this->insertSecret('bob', true);

		$this->runStep();

		$this->assertSame(['alice' => false, 'bob' => true], $this->verifiedByUser());
	}

	public function testDoesNothingWithoutInstalledVersion(): void {
		$this->setInstalledVersion(null);
		$this->insertSecret('alice', false);

		$this->runStep();

		$this->assertSame(['alice' => false], $this->verifiedByUser());
	}

	public function testSecondRunAfterTheUpdateIsNoop(): void {
		$this->setInstalledVersion('0.4.3');
		$this->insertSecret('alice', false);
		$this->runStep();

		// Der Kern schreibt nach den Schritten die neue Fassung; danach richtet
		// jemand TOTP neu ein, bestätigt aber noch nicht.
		$this->setInstalledVersion('0.10.4');
		$this->insertSecret('carol', false);
		$this->runStep();

		$this->assertSame(['alice' => true, 'carol' => false], $this->verifiedByUser());
	}

	public function testRepeatedRunOfAnAbortedUpdateGivesTheSameResult(): void {
		$this->setInstalledVersion('0.4.3');
		$this->insertSecret('alice', false);
		$this->insertSecret('bob', false);
		$this->runStep();
		$afterFirstRun = $this->verifiedByUser();

		// Nichts mehr zu markieren, also auch kein zweiter Protokolleintrag.
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->never())->method('warning');
		$this->runStep($logger);

		$this->assertSame($afterFirstRun, $this->verifiedByUser());
	}

	public function testLogsHowManyAccountsKeepTheirSecondFactor(): void {
		$this->setInstalledVersion('0.4.3');
		$this->insertSecret('alice', false);
		$this->insertSecret('bob', false);
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->logicalAnd(
					$this->stringContains('0.4.3'),
					$this->stringContains('2 existing TOTP secret(s)'),
					$this->stringContains('set-secret-verification-status false')
				),
				['app' => 'twofactor_totp']
			);

		$this->runStep($logger);
	}

	public function testStaysQuietWithoutSecrets(): void {
		$this->setInstalledVersion('0.4.3');
		$logger = $this->createMock(ILogger::class);
		$logger->expects($this->never())->method('warning');

		$this->runStep($logger);
	}

	public function testStepRunsAfterTheSchemaUpdateOnly(): void {
		$info = \OC::$server->getAppManager()->getAppInfo('twofactor_totp');

		$this->assertContains(VerifyLegacySecrets::class, $info['repair-steps']['post-migration']);
		$this->assertNotContains(VerifyLegacySecrets::class, $info['repair-steps']['pre-migration']);
		$this->assertNotContains(VerifyLegacySecrets::class, $info['repair-steps']['install']);
	}
}
