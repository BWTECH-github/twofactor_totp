<?php

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Semih Serhat Karakaya <karakayasemi@itu.edu.tr>
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

namespace OCA\TwoFactor_Totp\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;

class TotpSecretMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'twofactor_totp_secrets');
	}

	/**
	 * @param IUser $user
	 * @throws DoesNotExistException
	 * @return TotpSecret
	 */
	public function getSecret(IUser $user) {
		/* @var $qb IQueryBuilder */
		$qb = $this->db->getQueryBuilder();

		$qb->select('id', 'user_id', 'secret', 'verified', 'last_validated_key')
				->from('twofactor_totp_secrets')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())));
		$result = $qb->execute();

		$row = $result->fetchAssociative();
		$result->free();
		if ($row === false) {
			throw new DoesNotExistException('Secret does not exist');
		}
		return TotpSecret::fromRow($row);
	}

	/**
	 * @param boolean $status
	 */
	public function setAllSecretsVerificationStatus($status) {
		/* @var $qb IQueryBuilder */
		$qb = $this->db->getQueryBuilder();
		$qb->update('twofactor_totp_secrets')
			->set('verified', $qb->createNamedParameter($status, IQueryBuilder::PARAM_BOOL))
			->execute();
	}

	/**
	 * Markiert alle unbestätigten Schlüssel als bestätigt. NULL zählt als
	 * unbestätigt: Die Spalte ist nicht NOT NULL.
	 *
	 * @return int the number of secrets marked as verified
	 */
	public function markUnverifiedSecretsAsVerified(): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->update('twofactor_totp_secrets')
			->set('verified', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->orX(
				$qb->expr()->eq('verified', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
				$qb->expr()->isNull('verified')
			))
			->execute();
	}

	/**
	 * @param string $uid
	 * @return int the number of deleted secrets
	 */
	public function deleteSecretsByUserId($uid) {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('twofactor_totp_secrets')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->execute();
	}

	/**
	 * Remove all the secrets from all the users
	 *
	 * @return int the number of deleted secrets
	 */
	public function deleteAllSecrets() {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('twofactor_totp_secrets')->execute();
	}

	public function getAllSecrets() {
		$qb = $this->db->getQueryBuilder();
		return $qb ->select('*')
			->from('twofactor_totp_secrets')
			->execute()
			->fetchAllAssociative();
	}

	/**
	 * Whether the stored secret of each account is verified, ordered by
	 * account ID. Reads neither the secret nor the last key.
	 *
	 * @param string[]|null $uids only these accounts; null for all accounts
	 * @return array<string,bool> account ID => verified
	 */
	public function getSecretStatesByUserId(?array $uids = null): array {
		if ($uids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'verified')
			->from('twofactor_totp_secrets')
			->orderBy('user_id');
		if ($uids !== null) {
			$qb->where($qb->expr()->in(
				'user_id',
				$qb->createNamedParameter(\array_values($uids), IQueryBuilder::PARAM_STR_ARRAY)
			));
		}

		$result = $qb->execute();
		$states = [];
		while (($row = $result->fetchAssociative()) !== false) {
			// Je nach Datenbank kommt die Spalte als bool, int oder Zeichenkette;
			// Altbestände tragen NULL (siehe markUnverifiedSecretsAsVerified).
			$states[(string)$row['user_id']] = \in_array($row['verified'], [true, 1, '1', 't', 'true'], true);
		}
		$result->free();
		return $states;
	}

	/**
	 * Number of accounts with a secret; user_id is unique, so one row per account.
	 */
	public function countUsersWithSecret(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from('twofactor_totp_secrets');
		$result = $qb->execute();
		$count = (int)$result->fetchOne();
		$result->free();
		return $count;
	}
}
