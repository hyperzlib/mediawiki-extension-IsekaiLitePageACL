<?php

namespace Isekai\LitePageACL\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ImportRevisionEditorsToPermissionTable extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Import page editors into isekai_lpacl_page_actor and backfill registered page creators in isekai_lpacl_participant.'
		);
		$this->addOption( 'role', 'Role key to grant to imported editors.', true, true );
		$this->addOption( 'overwrite', 'Replace existing page actor grants for imported editor rows.' );
		$this->addOption( 'dry-run', 'Count rows without inserting them.' );
		$this->addOption( 'page-id', 'Only import editors for this page ID.', false, true );
		$this->setBatchSize( 500 );
		$this->requireExtension( 'IsekaiLitePageACL' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$loadBalancer = $services->getDBLoadBalancer();
		$dbr = $loadBalancer->getConnection( DB_REPLICA );
		$dbw = $loadBalancer->getConnection( DB_PRIMARY );

		$roleKey = (string)$this->getOption( 'role' );
		$role = $services->getService( 'IsekaiLitePageACL.RoleStore' )->getRoleByKey( $roleKey );
		if ( !$role ) {
			$this->fatalError( "Role '$roleKey' does not exist." );
		}
		if ( empty( $role['enabled'] ) ) {
			$this->fatalError( "Role '$roleKey' is disabled." );
		}

		$dryRun = $this->hasOption( 'dry-run' );
		$overwrite = $this->hasOption( 'overwrite' );
		$batchSize = $this->getBatchSize();
		$pageId = $this->getOption( 'page-id' );
		if ( $pageId !== null ) {
			$pageId = (int)$pageId;
			if ( $pageId <= 0 ) {
				$this->fatalError( '--page-id must be a positive integer.' );
			}
		}

		$totalCandidates = 0;
		$totalWritten = 0;
		$lastPageId = 0;

		do {
			$pageIds = $pageId !== null
				? [ $pageId ]
				: $this->getNextPageIds( $dbr, $lastPageId, $batchSize );

			if ( !$pageIds ) {
				break;
			}

			$lastPageId = max( $pageIds );
			$rows = $this->getEditorRows( $dbr, $pageIds, $overwrite );
			$candidateCount = count( $rows );
			$totalCandidates += $candidateCount;

			if ( $candidateCount > 0 ) {
				if ( $dryRun ) {
					$this->output(
						"Found $candidateCount editor grants to import in page batch ending at $lastPageId.\n"
					);
				} else {
					$this->ensurePageAclRows( $dbr, $dbw, $pageIds );
					$written = $this->writeEditorGrants( $dbw, $rows, $roleKey, $overwrite );
					$totalWritten += $written;
					$this->output(
						"Imported $written editor grants from $candidateCount candidates in page batch ending at $lastPageId.\n"
					);
					$this->waitForReplication();
				}
			}

			$creatorCount = $this->ensureCreatorParticipants( $dbr, $dbw, $pageIds, $dryRun );
			if ( $creatorCount > 0 ) {
				$this->output( $dryRun
					? "Found $creatorCount page creator participant records to add in page batch ending at $lastPageId.\n"
					: "Added $creatorCount page creator participant records in page batch ending at $lastPageId.\n"
				);
				if ( !$dryRun ) {
					$this->waitForReplication();
				}
			}

			if ( $pageId !== null ) {
				break;
			}
		} while ( count( $pageIds ) === $batchSize );

		if ( $dryRun ) {
			$this->output( "Dry run complete. Editor grants to import: $totalCandidates.\n" );
		} else {
			$this->output(
				"Import complete. Imported $totalWritten editor grants from $totalCandidates candidates.\n"
			);
		}

		return true;
	}

	/**
	 * @return int[]
	 */
	private function getNextPageIds( IReadableDatabase $dbr, int $lastPageId, int $limit ): array {
		$res = $dbr->select(
			'page',
			'page_id',
			[ $dbr->expr( 'page_id', '>', $lastPageId ) ],
			__METHOD__,
			[
				'ORDER BY' => 'page_id',
				'LIMIT' => $limit,
			]
		);

		$pageIds = [];
		foreach ( $res as $row ) {
			$pageIds[] = (int)$row->page_id;
		}

		return $pageIds;
	}

	/**
	 * @param int[] $pageIds
	 * @return array<int,\stdClass>
	 */
	private function getEditorRows( IReadableDatabase $dbr, array $pageIds, bool $overwrite ): array {
		if ( !$pageIds ) {
			return [];
		}

		$query = $dbr->newSelectQueryBuilder()
			->select( [
				'page_id' => 'rev.rev_page',
				'actor_id' => 'rev.rev_actor',
				'first_seen' => 'MIN(rev.rev_timestamp)',
				'last_seen' => 'MAX(rev.rev_timestamp)',
			] )
			->from( 'revision', 'rev' )
			->where( [
				'rev.rev_page' => $pageIds,
				$dbr->expr( 'rev.rev_actor', '>', 0 ),
			] )
			->groupBy( [ 'rev.rev_page', 'rev.rev_actor' ] )
			->orderBy( [ 'rev.rev_page', 'rev.rev_actor' ] )
			->caller( __METHOD__ );

		if ( !$overwrite ) {
			$query->leftJoin(
				'isekai_lpacl_page_actor',
				'lpacl_actor',
				[
					'lpacl_actor.page_id = rev.rev_page',
					'lpacl_actor.actor_id = rev.rev_actor',
				]
			)->andWhere( [ 'lpacl_actor.page_id' => null ] );
		}

		$res = $query->fetchResultSet();
		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @param int[] $pageIds
	 */
	private function ensurePageAclRows( IReadableDatabase $dbr, IDatabase $dbw, array $pageIds ): void {
		$existingRows = $dbr->select(
			'isekai_lpacl_page',
			'page_id',
			[ 'page_id' => $pageIds ],
			__METHOD__
		);
		$existing = [];
		foreach ( $existingRows as $row ) {
			$existing[(int)$row->page_id] = true;
		}

		$insertRows = [];
		foreach ( $pageIds as $pageId ) {
			if ( isset( $existing[$pageId] ) ) {
				continue;
			}
			$firstRevision = $dbr->selectRow(
				'revision',
				[ 'rev_actor', 'rev_timestamp' ],
				[ 'rev_page' => $pageId, $dbr->expr( 'rev_actor', '>', 0 ) ],
				__METHOD__,
				[ 'ORDER BY' => [ 'rev_timestamp', 'rev_id' ] ]
			);
			if ( !$firstRevision ) {
				continue;
			}
			$insertRows[] = [
				'page_id' => $pageId,
				'owner_actor_id' => (int)$firstRevision->rev_actor,
				'inherit' => 0,
				'acl_version' => 1,
				'created_at' => $firstRevision->rev_timestamp,
				'updated_at' => $dbw->timestamp(),
			];
		}

		if ( $insertRows ) {
			$dbw->insert(
				'isekai_lpacl_page',
				$insertRows,
				__METHOD__,
				[ 'IGNORE' ]
			);
		}
	}

	/**
	 * @param int[] $pageIds
	 */
	private function ensureCreatorParticipants(
		IReadableDatabase $dbr,
		IDatabase $dbw,
		array $pageIds,
		bool $dryRun
	): int {
		$existingRows = $dbr->select(
			'isekai_lpacl_participant',
			[ 'page_id', 'actor_id' ],
			[ 'page_id' => $pageIds, 'participant_type' => 'creator' ],
			__METHOD__
		);
		$existing = [];
		foreach ( $existingRows as $row ) {
			$existing[(int)$row->page_id][(int)$row->actor_id] = true;
		}

		$missing = [];
		foreach ( $pageIds as $pageId ) {
			$creator = $dbr->selectRow(
				[ 'rev' => 'revision', 'actor' => 'actor' ],
				[
					'actor_id' => 'rev.rev_actor',
					'first_seen' => 'rev.rev_timestamp',
				],
				[
					'rev.rev_page' => $pageId,
					'rev.rev_parent_id' => 0,
					'actor.actor_id = rev.rev_actor',
					$dbr->expr( 'actor.actor_user', '>', 0 ),
				],
				__METHOD__,
				[ 'ORDER BY' => [ 'rev.rev_timestamp', 'rev.rev_id' ] ]
			);
			if ( !$creator ) {
				continue;
			}

			$actorId = (int)$creator->actor_id;
			if ( isset( $existing[$pageId][$actorId] ) ) {
				continue;
			}
			$missing[] = [
				'page_id' => $pageId,
				'actor_id' => $actorId,
				'participant_type' => 'creator',
				'first_seen' => $creator->first_seen,
				'last_seen' => $creator->first_seen,
			];
		}

		if ( $dryRun || !$missing ) {
			return count( $missing );
		}

		$inserted = 0;
		foreach ( $missing as $row ) {
			$dbw->insert( 'isekai_lpacl_participant', $row, __METHOD__, [ 'IGNORE' ] );
			$inserted += $dbw->affectedRows();
		}
		return $inserted;
	}

	/**
	 * @param array<int,\stdClass> $rows
	 */
	private function writeEditorGrants( IDatabase $dbw, array $rows, string $roleKey, bool $overwrite ): int {
		$written = 0;
		foreach ( $rows as $row ) {
			$pageId = (int)$row->page_id;
			$actorId = (int)$row->actor_id;
			if ( $overwrite ) {
				$dbw->delete(
					'isekai_lpacl_page_actor',
					[ 'page_id' => $pageId, 'actor_id' => $actorId ],
					__METHOD__
				);
			}
			$written += $this->insertPageActorGrant(
				$dbw,
				$pageId,
				$actorId,
				[ $roleKey ],
				[],
				$actorId,
				(string)$row->first_seen,
				(string)$row->last_seen
			);
		}
		return $written;
	}

	/**
	 * @param string[] $roles
	 * @param string[] $permissions
	 */
	private function insertPageActorGrant(
		IDatabase $dbw,
		int $pageId,
		int $actorId,
		array $roles,
		array $permissions,
		int $grantedByActorId,
		string $grantedAt,
		string $updatedAt
	): int {
		if ( $dbw->getType() === 'mysql' ) {
			$table = $dbw->tableName( 'isekai_lpacl_page_actor' );
			$rolesJson = json_encode( array_values( array_unique( array_map( 'strval', $roles ) ) ) );
			$permissionsJson = json_encode( array_values( array_unique( array_map( 'strval', $permissions ) ) ) );
			$sql = "INSERT IGNORE INTO $table " .
				"(page_id, actor_id, roles, permissions, granted_by_actor_id, granted_at, updated_at) VALUES (" .
				$pageId . ', ' .
				$actorId . ', ' .
				'CONVERT(' . $dbw->addQuotes( $rolesJson ) . ' USING utf8mb4), ' .
				'CONVERT(' . $dbw->addQuotes( $permissionsJson ) . ' USING utf8mb4), ' .
				$grantedByActorId . ', ' .
				$dbw->addQuotes( $grantedAt ) . ', ' .
				$dbw->addQuotes( $updatedAt ) .
				')';
			$dbw->query( $sql, __METHOD__ );
			return $dbw->affectedRows();
		}

		$dbw->insert(
			'isekai_lpacl_page_actor',
			[
				'page_id' => $pageId,
				'actor_id' => $actorId,
				'roles' => $this->encodeArrayField( $roles ),
				'permissions' => $this->encodeArrayField( $permissions ),
				'granted_by_actor_id' => $grantedByActorId,
				'granted_at' => $grantedAt,
				'updated_at' => $updatedAt,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
		return $dbw->affectedRows();
	}

	/**
	 * @param string[] $values
	 */
	private function encodeArrayField( array $values ): string {
		$values = array_values( array_unique( array_map( 'strval', $values ) ) );
		return '{' . implode( ',', array_map( [ $this, 'encodePostgresArrayValue' ], $values ) ) . '}';
	}

	private function encodePostgresArrayValue( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}
}

$maintClass = ImportRevisionEditorsToPermissionTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
