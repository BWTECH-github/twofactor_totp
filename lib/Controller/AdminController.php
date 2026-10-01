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
use OCP\IUser;
use OCP\IUserManager;

class AdminController extends Controller {
	/** Accounts per page of the admin panel */
	public const PAGE_SIZE = 25;

	/** Upper bound for one page, whatever the client asks for */
	public const MAX_PAGE_SIZE = 100;

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
	 * One page of accounts for the admin panel.
	 *
	 * Without the TOTP filter the core account search decides which accounts
	 * match, exactly as in the user management. With the filter only accounts
	 * holding a secret (verified or not) are listed, sorted by display name.
	 *
	 * @param string $query
	 * @param int $limit
	 * @param int $offset
	 * @param bool $onlyTotp
	 * @return JSONResponse
	 */
	public function searchUsers($query = '', $limit = self::PAGE_SIZE, $offset = 0, $onlyTotp = false) {
		$query = \trim((string)$query);
		$limit = \max(1, \min(self::MAX_PAGE_SIZE, (int)$limit));
		$offset = \max(0, (int)$offset);

		if (\filter_var($onlyTotp, FILTER_VALIDATE_BOOLEAN)) {
			$states = $this->secretMapper->getSecretStatesByUserId();
			$matches = $this->findAccountsWithSecret($states, $query);
			$total = \count($matches);
			$page = \array_slice($matches, $offset, $limit);
			$hasMore = $offset + \count($page) < $total;
		} else {
			// Ein Konto mehr anfragen: so zeigt sich ohne Zählabfrage, ob es weitergeht.
			$found = \array_values($this->userManager->find($query, $limit + 1, $offset));
			$hasMore = \count($found) > $limit;
			$page = \array_slice($found, 0, $limit);
			$total = null;
			$states = $this->secretMapper->getSecretStatesByUserId(\array_map(static function (IUser $user) {
				return $user->getUID();
			}, $page));
		}

		$users = [];
		foreach ($page as $user) {
			$uid = $user->getUID();
			$users[] = [
				'uid' => $uid,
				'userName' => $user->getUserName(),
				'displayName' => $user->getDisplayName(),
				'email' => $user->getEMailAddress(),
				'enabled' => $user->isEnabled(),
				'hasTotp' => isset($states[$uid]),
				'totpVerified' => $states[$uid] ?? false,
			];
		}

		return new JSONResponse([
			'status' => 'success',
			'users' => $users,
			'limitedTo' => $limit,
			'offset' => $offset,
			'hasMore' => $hasMore,
			'nextOffset' => $hasMore ? $offset + \count($page) : null,
			'total' => $total,
			'totpCount' => $this->secretMapper->countUsersWithSecret(),
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
	 * Accounts that hold a secret and match the query, sorted by display name.
	 *
	 * Looks up every account with a secret once per request. That stays cheap
	 * as long as TOTP is used by hundreds, not tens of thousands of accounts;
	 * secrets of deleted accounts are skipped.
	 *
	 * @param array<string,bool> $states account ID => verified
	 * @param string $query
	 * @return IUser[]
	 */
	private function findAccountsWithSecret(array $states, $query) {
		$matches = [];
		foreach (\array_keys($states) as $uid) {
			// Numerische Kennungen werden als Array-Schlüssel zu int.
			$user = $this->userManager->get((string)$uid);
			if ($user !== null && $this->matchesQuery($user, $query)) {
				$matches[] = $user;
			}
		}

		\usort($matches, static function (IUser $a, IUser $b) {
			return \strnatcasecmp((string)$a->getDisplayName(), (string)$b->getDisplayName())
				?: \strcmp($a->getUID(), $b->getUID());
		});
		return $matches;
	}

	/**
	 * Case-insensitive substring match on the fields the panel shows.
	 *
	 * @param IUser $user
	 * @param string $query
	 * @return bool
	 */
	private function matchesQuery(IUser $user, $query) {
		if ($query === '') {
			return true;
		}
		$fields = [$user->getUID(), $user->getUserName(), $user->getDisplayName(), $user->getEMailAddress()];
		foreach ($fields as $value) {
			if ($value !== null && $value !== '' && \mb_stripos((string)$value, $query) !== false) {
				return true;
			}
		}
		return false;
	}
}
