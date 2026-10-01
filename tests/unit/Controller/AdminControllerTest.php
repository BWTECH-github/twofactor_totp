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

namespace OCA\TwoFactor_Totp\Unit\Controller;

use OCA\TwoFactor_Totp\Controller\AdminController;
use OCA\TwoFactor_Totp\Db\TotpSecretMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use Test\TestCase;

class AdminControllerTest extends TestCase {
	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var TotpSecretMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $secretMapper;

	/** @var AdminController */
	private $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->secretMapper = $this->createMock(TotpSecretMapper::class);

		$this->controller = new AdminController(
			'twofactor_totp',
			$this->request,
			$this->userManager,
			$this->secretMapper
		);
	}

	public function testResetUserTotpRequiresUserId() {
		$this->secretMapper->expects($this->never())
			->method('deleteSecretsByUserId');

		$expected = new JSONResponse([
			'status' => 'error',
			'message' => 'User ID is required',
		], Http::STATUS_BAD_REQUEST);
		$this->assertEquals($expected, $this->controller->resetUserTotp(''));
	}

	public function testResetUserTotpRequiresExistingUser() {
		$this->userManager->expects($this->once())
			->method('get')
			->with('missing')
			->willReturn(null);
		$this->secretMapper->expects($this->never())
			->method('deleteSecretsByUserId');

		$expected = new JSONResponse([
			'status' => 'error',
			'message' => 'User not found',
		], Http::STATUS_NOT_FOUND);
		$this->assertEquals($expected, $this->controller->resetUserTotp('missing'));
	}

	public function testResetUserTotpDeletesSecret() {
		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with('alice')
			->willReturn($user);
		$this->secretMapper->expects($this->once())
			->method('deleteSecretsByUserId')
			->with('alice')
			->willReturn(1);

		$expected = new JSONResponse([
			'status' => 'success',
			'uid' => 'alice',
			'deleted' => 1,
		]);
		$this->assertEquals($expected, $this->controller->resetUserTotp(' alice '));
	}

	public function testSearchAllAccountsAsksForOneExtraAccountToDetectAFurtherPage() {
		$alice = $this->createUserMock('alice', 'alice.user', 'Alice User', 'alice@example.test', true);
		$bob = $this->createUserMock('bob', 'bob', 'Bob', null, false);
		$carol = $this->createUserMock('carol', 'carol', 'Carol', 'carol@example.test', true);
		$this->userManager->expects($this->once())
			->method('find')
			->with('ali', 3, 10)
			->willReturn(['alice' => $alice, 'bob' => $bob, 'carol' => $carol]);
		$this->userManager->expects($this->never())
			->method('get');
		$this->secretMapper->expects($this->once())
			->method('getSecretStatesByUserId')
			->with(['alice', 'bob'])
			->willReturn(['alice' => true]);
		$this->secretMapper->method('countUsersWithSecret')
			->willReturn(5);

		$response = $this->controller->searchUsers(' ali ', 2, 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'status' => 'success',
			'users' => [
				[
					'uid' => 'alice',
					'userName' => 'alice.user',
					'displayName' => 'Alice User',
					'email' => 'alice@example.test',
					'enabled' => true,
					'hasTotp' => true,
					'totpVerified' => true,
				],
				[
					'uid' => 'bob',
					'userName' => 'bob',
					'displayName' => 'Bob',
					'email' => null,
					'enabled' => false,
					'hasTotp' => false,
					'totpVerified' => false,
				],
			],
			'limitedTo' => 2,
			'offset' => 10,
			'hasMore' => true,
			'nextOffset' => 12,
			'total' => null,
			'totpCount' => 5,
		], $response->getData());
	}

	public function testSearchAllAccountsLastPageOffersNoFurtherPage() {
		$alice = $this->createUserMock('alice', 'alice', 'Alice', null, true);
		$this->userManager->expects($this->once())
			->method('find')
			->with('', 26, 0)
			->willReturn(['alice' => $alice]);
		$this->secretMapper->method('getSecretStatesByUserId')
			->willReturn([]);

		$data = $this->controller->searchUsers()->getData();

		$this->assertSame(['alice'], \array_column($data['users'], 'uid'));
		$this->assertFalse($data['users'][0]['hasTotp']);
		$this->assertFalse($data['hasMore']);
		$this->assertNull($data['nextOffset']);
	}

	public function testSearchOnlyTotpListsAccountsWithSecretSortedByDisplayName() {
		$this->prepareTotpAccounts();

		$data = $this->controller->searchUsers('', 2, 0, true)->getData();

		$this->assertSame(['adam', 'mia'], \array_column($data['users'], 'uid'));
		$this->assertSame([true, true], \array_column($data['users'], 'hasTotp'));
		$this->assertSame([false, true], \array_column($data['users'], 'totpVerified'));
		// "gone" hat noch einen Schlüssel, aber kein Konto mehr: nicht in der Liste.
		$this->assertSame(3, $data['total']);
		$this->assertTrue($data['hasMore']);
		$this->assertSame(2, $data['nextOffset']);
		$this->assertSame(4, $data['totpCount']);
	}

	public function testSearchOnlyTotpSecondPageEndsTheList() {
		$this->prepareTotpAccounts();

		$data = $this->controller->searchUsers('', 2, 2, true)->getData();

		$this->assertSame(['zoe'], \array_column($data['users'], 'uid'));
		$this->assertFalse($data['hasMore']);
		$this->assertNull($data['nextOffset']);
	}

	/**
	 * @dataProvider totpQueryProvider
	 */
	public function testSearchOnlyTotpMatchesIdUserNameDisplayNameAndEmailIgnoringCase($query, array $expectedUids) {
		$this->prepareTotpAccounts();

		$data = $this->controller->searchUsers($query, 25, 0, '1')->getData();

		$this->assertSame($expectedUids, \array_column($data['users'], 'uid'));
		$this->assertSame(\count($expectedUids), $data['total']);
	}

	public function totpQueryProvider() {
		return [
			'display name in other case' => ['MAUS', ['mia']],
			'user name' => ['mia.m', ['mia']],
			'account id' => ['ada', ['adam']],
			'email' => ['zoe@example', ['zoe']],
			'no match' => ['nobody', []],
		];
	}

	public function testSearchOnlyTotpLooksUpNumericAccountIdsAsStrings() {
		// PHP macht aus dem Array-Schlüssel '123' die Zahl 123.
		$user = $this->createUserMock('123', '123', 'Numerisch', null, true);
		$this->secretMapper->method('getSecretStatesByUserId')
			->willReturn(['123' => true]);
		$this->userManager->expects($this->once())
			->method('get')
			->with($this->identicalTo('123'))
			->willReturn($user);

		$data = $this->controller->searchUsers('', 25, 0, true)->getData();

		$this->assertSame(['123'], \array_column($data['users'], 'uid'));
		$this->assertTrue($data['users'][0]['totpVerified']);
	}

	public function testSearchClampsPageSizeAndOffset() {
		$this->userManager->expects($this->once())
			->method('find')
			->with('', 101, 0)
			->willReturn([]);
		$this->secretMapper->method('getSecretStatesByUserId')
			->willReturn([]);

		$data = $this->controller->searchUsers('', 5000, -3)->getData();

		$this->assertSame(100, $data['limitedTo']);
		$this->assertSame(0, $data['offset']);
	}

	public function testSearchReturnsAtLeastOneAccountPerPage() {
		$this->userManager->expects($this->once())
			->method('find')
			->with('', 2, 0)
			->willReturn([]);
		$this->secretMapper->method('getSecretStatesByUserId')
			->willReturn([]);

		$this->assertSame(1, $this->controller->searchUsers('', 0)->getData()['limitedTo']);
	}

	public function testSearchTreatsFalseLikeFlagsAsAllAccounts() {
		$this->userManager->expects($this->exactly(2))
			->method('find')
			->willReturn([]);
		$this->secretMapper->method('getSecretStatesByUserId')
			->willReturn([]);

		$this->controller->searchUsers('', 25, 0, 'false');
		$this->controller->searchUsers('', 25, 0, '0');
	}

	public function testAdminEndpointsStayAdminOnlyAndCsrfProtected() {
		// Ohne diese Annotationen verlangt das AppFramework Administratorrechte
		// und ein gültiges CSRF-Token für jeden Aufruf - auch für die Suche.
		$class = new \ReflectionClass(AdminController::class);
		foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== AdminController::class || $method->isConstructor()) {
				continue;
			}
			$doc = (string)$method->getDocComment();
			foreach (['NoAdminRequired', 'NoCSRFRequired', 'PublicPage', 'CORS'] as $annotation) {
				$this->assertStringNotContainsStringIgnoringCase('@' . $annotation, $doc, $method->getName());
			}
		}
	}

	public function testAdminRoutesKeepTheirUrlsAndVerbs() {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';

		$adminRoutes = \array_values(\array_filter($routes['routes'], static function ($route) {
			return \strpos($route['name'], 'admin#') === 0;
		}));

		$this->assertSame([
			['name' => 'admin#resetUserTotp', 'url' => '/admin/reset-user', 'verb' => 'POST'],
			['name' => 'admin#searchUsers', 'url' => '/admin/users', 'verb' => 'GET'],
			['name' => 'admin#resetUsersTotp', 'url' => '/admin/reset-users', 'verb' => 'POST'],
			['name' => 'admin#resetAllTotp', 'url' => '/admin/reset-all', 'verb' => 'POST'],
		], $adminRoutes);
	}

	public function testResetUsersTotpRequiresSelection() {
		$expected = new JSONResponse([
			'status' => 'error',
			'message' => 'Select at least one user',
		], Http::STATUS_BAD_REQUEST);
		$this->assertEquals($expected, $this->controller->resetUsersTotp([]));
	}

	public function testResetUsersTotpDeletesMultipleUsers() {
		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->exactly(2))
			->method('get')
			->withConsecutive(['alice'], ['missing'])
			->willReturnOnConsecutiveCalls($user, null);
		$this->secretMapper->expects($this->once())
			->method('deleteSecretsByUserId')
			->with('alice')
			->willReturn(1);

		$expected = new JSONResponse([
			'status' => 'success',
			'deleted' => 1,
			'results' => [
				[
					'uid' => 'alice',
					'status' => 'success',
					'deleted' => 1,
				],
				[
					'uid' => 'missing',
					'status' => 'missing',
					'deleted' => 0,
				],
			],
		]);
		$this->assertEquals($expected, $this->controller->resetUsersTotp([' alice ', 'alice', 'missing']));
	}

	public function testResetAllTotpDeletesAllSecrets() {
		$this->secretMapper->expects($this->once())
			->method('deleteAllSecrets')
			->willReturn(3);

		$expected = new JSONResponse([
			'status' => 'success',
			'deleted' => 3,
		]);
		$this->assertEquals($expected, $this->controller->resetAllTotp());
	}

	/**
	 * Vier Schlüssel, davon einer ohne Konto ("gone") und einer unbestätigt
	 * ("adam"); der Filter darf die Kernsuche nie benutzen.
	 */
	private function prepareTotpAccounts() {
		$users = [
			'zoe' => $this->createUserMock('zoe', 'zoe', 'Zoe Zett', 'zoe@example.test', true),
			'adam' => $this->createUserMock('adam', 'adam', 'adam ant', null, true),
			'mia' => $this->createUserMock('mia', 'mia.m', 'Mia Maus', 'mia@example.test', false),
		];
		$this->secretMapper->method('getSecretStatesByUserId')
			->willReturn(['zoe' => true, 'adam' => false, 'gone' => true, 'mia' => true]);
		$this->secretMapper->method('countUsersWithSecret')
			->willReturn(4);
		$this->userManager->method('get')
			->willReturnCallback(static function ($uid) use ($users) {
				return $users[$uid] ?? null;
			});
		$this->userManager->expects($this->never())
			->method('find');
	}

	private function createUserMock($uid, $userName, $displayName, $email, $enabled) {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getUserName')->willReturn($userName);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}
}
