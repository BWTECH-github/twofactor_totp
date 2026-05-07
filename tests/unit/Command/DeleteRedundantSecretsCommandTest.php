<?php
// Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
/**
 * @author Sıla Boyraz <boyrazs15@itu.edu.tr>
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
 * @license AGPL-3.0
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

namespace OCA\TwoFactor_Totp\Tests\Command;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Test\Traits\UserTrait;
use OCA\TwoFactor_Totp\Command\DeleteRedundantSecretsCommand;
use OCA\TwoFactor_Totp\Db\TotpSecret;
use OCA\TwoFactor_Totp\Db\TotpSecretMapper;
use Symfony\Component\Console\Tester\CommandTester;
use Test\TestCase;

/**
 * Class DeleteRedundantSecretsCommandTest
 *
 * @group DB
 */
class DeleteRedundantSecretsCommandTest extends TestCase {
	use UserTrait;

	/** @var IDBConnection */
	private $db;

	/** @var CommandTester */
	private $commandTester;

	/** @var TotpSecretMapper */
	private $mapper;

	/** @var array */
	private $originalSecrets = [];

	/** @var string  */
	private $dbTable = 'twofactor_totp_secrets';

	protected function setUp(): void {
		parent::setUp();

		$this->db = \OC::$server->getDatabaseConnection();
		$this->mapper = new TotpSecretMapper($this->db);
		$this->originalSecrets = $this->mapper->getAllSecrets();
		$this->mapper->deleteAllSecrets();

		$command = new DeleteRedundantSecretsCommand($this->mapper, \OC::$server->getUserManager());
		$this->commandTester = new CommandTester($command);

		$this->createUser('user1');
		$this->mapper->insert(TotpSecret::fromParams([
			'userId' => 'user1',
			'secret' => 'test',
			'verified' => false
		]));
		$this->createUser('user2');
		$this->mapper->insert(TotpSecret::fromParams([
			'userId' => 'user2',
			'secret' => 'test',
			'verified' => false
		]));
		$this->mapper->insert(TotpSecret::fromParams([
			'userId' => 'nonexisting_user',
			'secret' => 'test',
			'verified' => false
		]));
	}

	protected function tearDown(): void {
		$this->mapper->deleteAllSecrets();
		foreach ($this->originalSecrets as $secret) {
			$this->restoreSecret($secret);
		}
		parent::tearDown();
	}

	public function testCommandInput() {
		$this->commandTester->execute([]);
		$output = $this->commandTester->getDisplay();
		$this->assertStringContainsString("The redundant secret of nonexisting_user is deleted.", $output);
		$this->assertStringContainsString("1 redundant secrets are deleted", $output);
	}

	/**
	 * @param array $secret
	 */
	private function restoreSecret(array $secret) {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->dbTable)
			->values([
				'user_id' => $qb->createNamedParameter($secret['user_id']),
				'secret' => $qb->createNamedParameter($secret['secret']),
				'verified' => $qb->createNamedParameter((bool)$secret['verified'], IQueryBuilder::PARAM_BOOL),
				'last_validated_key' => $qb->createNamedParameter($secret['last_validated_key']),
			])
			->execute();
	}
}
