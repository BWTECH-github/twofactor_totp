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

namespace OCA\TwoFactor_Totp\Controller;

use OCA\TwoFactor_Totp\Db\TotpSecretMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

class AdminController extends Controller {
	/** @var IUserManager */
	private $userManager;

	/** @var TotpSecretMapper */
	private $secretMapper;

	public function __construct(
		$appName,
		IRequest $request,
		IUserManager $userManager,
		TotpSecretMapper $secretMapper
	) {
		parent::__construct($appName, $request);
		$this->userManager = $userManager;
		$this->secretMapper = $secretMapper;
	}

	/**
	 * Reset one user's TOTP secret so the next login has to enroll a new device.
	 *
	 * @param string $uid
	 * @return JSONResponse
	 */
	public function resetUserTotp($uid) {
		$uid = \trim((string)$uid);
		if ($uid === '') {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'User ID is required',
			], Http::STATUS_BAD_REQUEST);
		}

		if ($this->userManager->get($uid) === null) {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'User not found',
			], Http::STATUS_NOT_FOUND);
		}

		$deleted = $this->secretMapper->deleteSecretsByUserId($uid);
		return new JSONResponse([
			'status' => 'success',
			'uid' => $uid,
			'deleted' => $deleted,
		]);
	}

	/**
	 * Search users by user id, username, display name and email.
	 *
	 * @param string $query
	 * @param int $limit
	 * @return JSONResponse
	 */
	public function searchUsers($query = '', $limit = 1000) {
		$query = \trim((string)$query);
		$limit = \max(1, \min(5000, (int)$limit));
		$users = $this->userManager->find($query, $limit, 0);
		$totpUserIds = $this->getTotpUserIdMap();
		$result = [];

		foreach ($users as $user) {
			$uid = $user->getUID();
			$result[] = [
				'uid' => $uid,
				'userName' => $user->getUserName(),
				'displayName' => $user->getDisplayName(),
				'email' => $user->getEMailAddress(),
				'enabled' => $user->isEnabled(),
				'hasTotp' => isset($totpUserIds[$uid]),
			];
		}

		return new JSONResponse([
			'status' => 'success',
			'users' => $result,
			'limitedTo' => $limit,
		]);
	}

	/**
	 * Reset TOTP secrets for multiple selected users.
	 *
	 * @param array|string $uids
	 * @return JSONResponse
	 */
	public function resetUsersTotp($uids = []) {
		if (!\is_array($uids)) {
			$uids = [$uids];
		}

		$uids = \array_values(\array_unique(\array_filter(\array_map(static function ($uid) {
			return \trim((string)$uid);
		}, $uids), static function ($uid) {
			return $uid !== '';
		})));

		if ($uids === []) {
			return new JSONResponse([
				'status' => 'error',
				'message' => 'Select at least one user',
			], Http::STATUS_BAD_REQUEST);
		}

		$results = [];
		$totalDeleted = 0;
		foreach ($uids as $uid) {
			if ($this->userManager->get($uid) === null) {
				$results[] = [
					'uid' => $uid,
					'status' => 'missing',
					'deleted' => 0,
				];
				continue;
			}

			$deleted = $this->secretMapper->deleteSecretsByUserId($uid);
			$totalDeleted += $deleted;
			$results[] = [
				'uid' => $uid,
				'status' => 'success',
				'deleted' => $deleted,
			];
		}

		return new JSONResponse([
			'status' => 'success',
			'deleted' => $totalDeleted,
			'results' => $results,
		]);
	}

	/**
	 * Reset every user's TOTP secret.
	 *
	 * @return JSONResponse
	 */
	public function resetAllTotp() {
		$deleted = $this->secretMapper->deleteAllSecrets();
		return new JSONResponse([
			'status' => 'success',
			'deleted' => $deleted,
		]);
	}

	/**
	 * @return array<string,bool>
	 */
	private function getTotpUserIdMap() {
		$map = [];
		foreach ($this->secretMapper->getAllSecrets() as $secret) {
			if (isset($secret['user_id'])) {
				$map[(string)$secret['user_id']] = true;
			}
		}
		return $map;
	}
}
