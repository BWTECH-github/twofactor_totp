<?php
/**
 * @author Semih Serhat Karakaya <karakayasemi@itu.edu.tr>
 * Modified by BW-Tech GmbH for owncloud.online PHP 8.4 compatibility.
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

namespace OCA\TwoFactor_Totp\Tests;

use OCA\TwoFactor_Totp\Db\TotpSecretMapper;
use OCA\TwoFactor_Totp\Hooks;
use OCP\App\ManagerEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Test\TestCase;

/**
 * Class HooksTest
 *
 * @package OCA\TwoFactor_Totp\Tests
 */
class HooksTest extends TestCase {
	/**
	 * @var Hooks $hooks
	 */
	private $hooks;

	/**
	 * @var EventDispatcherInterface | \PHPUnit\Framework\MockObject\MockObject $eventDispatcherMock
	 */
	private $eventDispatcherMock;

	/**
	 * @var TotpSecretMapper | \PHPUnit\Framework\MockObject\MockObject $secretMapperMock
	 */
	private $secretMapperMock;

	public function setUp(): void {
		parent::setUp();
		$this->eventDispatcherMock = $this->getMockBuilder(EventDispatcherInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->secretMapperMock = $this->getMockBuilder(TotpSecretMapper::class)
			->disableOriginalConstructor()
			->getMock();
		$this->hooks = new Hooks($this->eventDispatcherMock, $this->secretMapperMock);
	}

	public function testRegister() {
		$this->eventDispatcherMock->expects($this->exactly(2))
			->method('addListener');
		$this->hooks->register();
	}

	public function testAfterDeleteUser() {
		$this->secretMapperMock->expects($this->exactly(1))
			->method('deleteSecretsByUserId')
			->with('user1');
		$this->hooks->afterDeleteUser('user1');
	}

	public function testAfterDisableAppDeletesAllSecrets() {
		$this->secretMapperMock->expects($this->once())
			->method('deleteAllSecrets');
		$this->hooks->afterDisableApp(new ManagerEvent(ManagerEvent::EVENT_APP_DISABLE, 'twofactor_totp'));
	}

	public function testAfterDisableAppIgnoresOtherApps() {
		$this->secretMapperMock->expects($this->never())
			->method('deleteAllSecrets');
		$this->hooks->afterDisableApp(new ManagerEvent(ManagerEvent::EVENT_APP_DISABLE, 'files'));
	}
}
