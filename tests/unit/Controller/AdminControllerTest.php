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

	public function testSearchUsersReturnsUserDetails() {
		$user = $this->createUserMock('alice', 'alice.user', 'Alice User', 'alice@example.test', true);
		$this->userManager->expects($this->once())
			->method('find')
			->with('ali', 25, 0)
			->willReturn([$user]);
		$this->secretMapper->expects($this->once())
			->method('getAllSecrets')
			->willReturn([
				['user_id' => 'alice'],
			]);

		$expected = new JSONResponse([
			'status' => 'success',
			'users' => [[
				'uid' => 'alice',
				'userName' => 'alice.user',
				'displayName' => 'Alice User',
				'email' => 'alice@example.test',
				'enabled' => true,
				'hasTotp' => true,
			]],
			'limitedTo' => 25,
		]);
		$this->assertEquals($expected, $this->controller->searchUsers(' ali ', 25));
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
