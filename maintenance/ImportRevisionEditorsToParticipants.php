<?php

namespace Isekai\LitePageACL\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IReadableDatabase;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

class ImportRevisionEditorsToParticipants extends Maintenance {
	private const PARTICIPANT_TYPE = 'editor';

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Import page editors from the revision table into isekai_lpacl_participant.'
		);
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

		$dryRun = $this->hasOption( 'dry-run' );
		$batchSize = $this->getBatchSize();
		$pageId = $this->getOption( 'page-id' );
		if ( $pageId !== null ) {
			$pageId = (int)$pageId;
			if ( $pageId <= 0 ) {
				$this->fatalError( '--page-id must be a positive integer.' );
			}
		}

		$totalCandidates = 0;
		$totalInserted = 0;
		$lastPageId = 0;

		do {
			$pageIds = $pageId !== null
				? [ $pageId ]
				: $this->getNextPageIds( $dbr, $lastPageId, $batchSize );

			if ( !$pageIds ) {
				break;
			}

			$lastPageId = max( $pageIds );
			$rows = $this->getMissingEditorRows( $dbr, $pageIds );
			$candidateCount = count( $rows );
			$totalCandidates += $candidateCount;

			if ( $candidateCount > 0 ) {
				if ( $dryRun ) {
					$this->output(
						"Found $candidateCount missing editor participants in page batch ending at $lastPageId.\n"
					);
				} else {
					$insertRows = [];
					foreach ( $rows as $row ) {
						$insertRows[] = [
							'page_id' => (int)$row->page_id,
							'actor_id' => (int)$row->actor_id,
							'participant_type' => self::PARTICIPANT_TYPE,
							'first_seen' => $row->first_seen,
							'last_seen' => $row->last_seen,
						];
					}
					$dbw->insert(
						'isekai_lpacl_participant',
						$insertRows,
						__METHOD__,
						[ 'IGNORE' ]
					);
					$inserted = $dbw->affectedRows();
					$totalInserted += $inserted;
					$this->output(
						"Inserted $inserted editor participants from $candidateCount candidates in page batch ending at $lastPageId.\n"
					);
					$loadBalancer->waitForReplication();
				}
			}

			if ( $pageId !== null ) {
				break;
			}
		} while ( count( $pageIds ) === $batchSize );

		if ( $dryRun ) {
			$this->output( "Dry run complete. Missing editor participants: $totalCandidates.\n" );
		} else {
			$this->output(
				"Import complete. Inserted $totalInserted editor participants from $totalCandidates candidates.\n"
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
	private function getMissingEditorRows( IReadableDatabase $dbr, array $pageIds ): array {
		if ( !$pageIds ) {
			return [];
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'page_id' => 'rev.rev_page',
				'actor_id' => 'rev.rev_actor',
				'first_seen' => 'MIN(rev.rev_timestamp)',
				'last_seen' => 'MAX(rev.rev_timestamp)',
			] )
			->from( 'revision', 'rev' )
			->leftJoin(
				'isekai_lpacl_participant',
				'lpacl_participant',
				[
					'lpacl_participant.page_id = rev.rev_page',
					'lpacl_participant.actor_id = rev.rev_actor',
					'lpacl_participant.participant_type' => self::PARTICIPANT_TYPE,
				]
			)
			->where( [
				'rev.rev_page' => $pageIds,
				$dbr->expr( 'rev.rev_actor', '>', 0 ),
				'lpacl_participant.page_id' => null,
			] )
			->groupBy( [ 'rev.rev_page', 'rev.rev_actor' ] )
			->orderBy( [ 'rev.rev_page', 'rev.rev_actor' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$rows = [];
		foreach ( $res as $row ) {
			$rows[] = $row;
		}

		return $rows;
	}
}

$maintClass = ImportRevisionEditorsToParticipants::class;
require_once RUN_MAINTENANCE_IF_MAIN;
