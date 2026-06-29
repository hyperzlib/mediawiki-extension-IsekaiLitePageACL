<?php

namespace Isekai\LitePageACL\Service;

use Isekai\LitePageACL\Model\PageAclData;
use MediaWiki\Page\PageIdentity;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class PageAclStore {
	private ILoadBalancer $loadBalancer;
	private ActorNormalization $actorNormalization;

	/** @var array<int,PageAclData> */
	private array $localAclCache = [];

	public function __construct( ILoadBalancer $loadBalancer, ActorNormalization $actorNormalization ) {
		$this->loadBalancer = $loadBalancer;
		$this->actorNormalization = $actorNormalization;
	}

	public function getLocalAclForPage( PageIdentity $page ): PageAclData {
		return $this->getLocalAclForPageId( $page->getId() );
	}

	public function getLocalAclForPageId( int $pageId ): PageAclData {
		if ( isset( $this->localAclCache[$pageId] ) ) {
			return $this->localAclCache[$pageId];
		}
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'isekai_lpacl_page',
			'*',
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		if ( !$row ) {
			$data = PageAclData::empty( $pageId );
			$this->localAclCache[$pageId] = $data;
			return $data;
		}

		$data = PageAclData::empty( $pageId );
		$data->ownerActorId = (int)$row->owner_actor_id;
		$data->inherit = (bool)$row->inherit;
		$data->aclVersion = (int)$row->acl_version;

		$actorRows = $dbr->select(
			'isekai_lpacl_page_actor',
			[ 'actor_id', 'roles', 'permissions', 'granted_by_actor_id', 'granted_at', 'updated_at' ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		foreach ( $actorRows as $actorRow ) {
			$actorId = (int)$actorRow->actor_id;
			$data->grants[$actorId] = [
				'actor_id' => $actorId,
				'roles' => $this->decodeArrayField( $dbr->getType(), $actorRow->roles ),
				'permissions' => $this->decodeArrayField( $dbr->getType(), $actorRow->permissions ),
			];
		}

		$this->localAclCache[$pageId] = $data;
		return $data;
	}

	/**
	 * @param array[] $grants
	 */
	public function savePageAcl(
		PageIdentity $page,
		UserIdentity $performer,
		bool $inherit,
		array $grants,
		?int $expectedVersion
	): PageAclData {
		$pageId = $page->getId();
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$performerActorId = $this->actorNormalization->acquireActorId( $performer, $dbw );
		$now = $dbw->timestamp();
		$dbw->startAtomic( __METHOD__ );
		try {
			$row = $dbw->selectRow(
				'isekai_lpacl_page',
				[ 'acl_version', 'owner_actor_id' ],
				[ 'page_id' => $pageId ],
				__METHOD__,
				[ 'FOR UPDATE' ]
			);
			if ( $row && $expectedVersion !== null && (int)$row->acl_version !== $expectedVersion ) {
				throw new PageAclVersionConflictException();
			}
			$newVersion = $row ? (int)$row->acl_version + 1 : 1;
			$ownerActorId = $row ? (int)$row->owner_actor_id : $performerActorId;

			if ( $row ) {
				$dbw->update(
					'isekai_lpacl_page',
					[
						'inherit' => $inherit ? 1 : 0,
						'acl_version' => $newVersion,
						'updated_at' => $now,
					],
					[ 'page_id' => $pageId ],
					__METHOD__
				);
			} else {
				$dbw->insert(
					'isekai_lpacl_page',
					[
						'page_id' => $pageId,
						'owner_actor_id' => $ownerActorId,
						'inherit' => $inherit ? 1 : 0,
						'acl_version' => $newVersion,
						'created_at' => $now,
						'updated_at' => $now,
					],
					__METHOD__
				);
			}

			$dbw->delete( 'isekai_lpacl_page_actor', [ 'page_id' => $pageId ], __METHOD__ );

			foreach ( $grants as $grant ) {
				$this->insertPageActorGrant( $dbw, $pageId, $performerActorId, $now, $grant );
			}
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $e ) {
			$dbw->cancelAtomic( __METHOD__ );
			throw $e;
		}

		unset( $this->localAclCache[$pageId] );
		return $this->getLocalAclForPageId( $pageId );
	}

	public function markParticipant( PageIdentity $page, UserIdentity $user, string $type ): void {
		if ( !$page->getId() || !$user->isRegistered() ) {
			return;
		}
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$actorId = $this->actorNormalization->acquireActorId( $user, $dbw );
		$now = $dbw->timestamp();
		$row = $dbw->selectRow(
			'isekai_lpacl_participant',
			[ 'page_id' ],
			[ 'page_id' => $page->getId(), 'actor_id' => $actorId, 'participant_type' => $type ],
			__METHOD__
		);
		if ( $row ) {
			$dbw->update(
				'isekai_lpacl_participant',
				[ 'last_seen' => $now ],
				[ 'page_id' => $page->getId(), 'actor_id' => $actorId, 'participant_type' => $type ],
				__METHOD__
			);
		} else {
			$dbw->insert(
				'isekai_lpacl_participant',
				[
					'page_id' => $page->getId(),
					'actor_id' => $actorId,
					'participant_type' => $type,
					'first_seen' => $now,
					'last_seen' => $now,
				],
				__METHOD__
			);
		}
	}

	public function actorHasParticipantType( int $pageId, int $actorId, string $type ): bool {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$hasParticipant = (bool)$dbr->selectField(
			'isekai_lpacl_participant',
			'1',
			[ 'page_id' => $pageId, 'actor_id' => $actorId, 'participant_type' => $type ],
			__METHOD__
		);
		if ( $hasParticipant ) {
			return true;
		}
		if ( $type === 'creator' ) {
			return (bool)$dbr->selectField(
				'revision',
				'1',
				[ 'rev_page' => $pageId, 'rev_actor' => $actorId, 'rev_parent_id' => 0 ],
				__METHOD__
			);
		}
		if ( $type === 'editor' ) {
			return (bool)$dbr->selectField(
				'revision',
				'1',
				[ 'rev_page' => $pageId, 'rev_actor' => $actorId ],
				__METHOD__
			);
		}
		return false;
	}

	/**
	 * @param string[] $values
	 */
	private function encodeArrayField( IDatabase $db, array $values ) {
		$values = array_values( array_unique( array_map( 'strval', $values ) ) );
		if ( $db->getType() === 'postgres' ) {
			return '{' . implode( ',', array_map( [ $this, 'encodePostgresArrayValue' ], $values ) ) . '}';
		}
		return json_encode( $values );
	}

	/**
	 * @param array $grant
	 */
	private function insertPageActorGrant(
		IDatabase $dbw,
		int $pageId,
		int $performerActorId,
		string $now,
		array $grant
	): void {
		$actorId = (int)$grant['actor_id'];
		$roles = $grant['roles'] ?? [];
		$permissions = $grant['permissions'] ?? [];
		if ( $dbw->getType() === 'mysql' ) {
			$table = $dbw->tableName( 'isekai_lpacl_page_actor' );
			$rolesJson = json_encode( array_values( array_unique( array_map( 'strval', $roles ) ) ) );
			$permissionsJson = json_encode( array_values( array_unique( array_map( 'strval', $permissions ) ) ) );
			$sql = "INSERT INTO $table " .
				"(page_id, actor_id, roles, permissions, granted_by_actor_id, granted_at, updated_at) VALUES (" .
				(int)$pageId . ', ' .
				$actorId . ', ' .
				'CONVERT(' . $dbw->addQuotes( $rolesJson ) . ' USING utf8mb4), ' .
				'CONVERT(' . $dbw->addQuotes( $permissionsJson ) . ' USING utf8mb4), ' .
				$performerActorId . ', ' .
				$dbw->addQuotes( $now ) . ', ' .
				$dbw->addQuotes( $now ) .
				')';
			$dbw->query( $sql, __METHOD__ );
			return;
		}
		$dbw->insert(
			'isekai_lpacl_page_actor',
			[
				'page_id' => $pageId,
				'actor_id' => $actorId,
				'roles' => $this->encodeArrayField( $dbw, $roles ),
				'permissions' => $this->encodeArrayField( $dbw, $permissions ),
				'granted_by_actor_id' => $performerActorId,
				'granted_at' => $now,
				'updated_at' => $now,
			],
			__METHOD__
		);
	}

	/**
	 * @return string[]
	 */
	private function decodeArrayField( string $dbType, $value ): array {
		if ( $value === null || $value === '' ) {
			return [];
		}
		if ( $dbType === 'postgres' ) {
			return $this->decodePostgresTextArray( (string)$value );
		}
		$decoded = json_decode( (string)$value, true );
		return is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : [];
	}

	private function encodePostgresArrayValue( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}

	/**
	 * @return string[]
	 */
	private function decodePostgresTextArray( string $value ): array {
		$value = trim( $value );
		if ( $value === '{}' ) {
			return [];
		}
		$value = trim( $value, '{}' );
		$items = str_getcsv( $value, ',', '"', '\\' );
		return array_values( array_map( 'strval', $items ) );
	}
}
