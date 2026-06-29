<?php

namespace Isekai\LitePageACL\Job;

use Job;
use MediaWiki\MediaWikiServices;

class RebuildInheritanceIndexJob extends Job {
	public function __construct( array $params ) {
		parent::__construct( 'IsekaiLitePageACLRebuildInheritanceIndex', $params );
		$this->removeDuplicates = true;
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$dbw = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$manager = $services->getService( 'IsekaiLitePageACL.PermissionManager' );
		$rootPageId = (int)$this->params['root_page_id'];
		$title = $manager->getTitleFromPageId( $rootPageId );
		if ( !$title ) {
			$dbw->delete(
				'isekai_lpacl_pending_reindex',
				[ 'root_page_id' => $rootPageId, 'reason' => $this->params['reason'] ?? 'move' ],
				__METHOD__
			);
			return true;
		}

		$like = $dbw->buildLike( $title->getDBkey() . '/', $dbw->anyString() );
		$rows = $dbw->select(
			'page',
			[ 'page_id', 'page_namespace', 'page_title' ],
			[
				'page_namespace' => $title->getNamespace(),
				$dbw->expr( 'page_title', '=', $title->getDBkey() )->or( 'page_title', 'LIKE', $like ),
			],
			__METHOD__
		);

		foreach ( $rows as $row ) {
			$page = $services->getTitleFactory()->makeTitle( (int)$row->page_namespace, (string)$row->page_title );
			$chain = $manager->getParentChainPageIds( $page );
			$dbw->replace(
				'isekai_lpacl_inherit_index',
				[ [ 'page_id' ] ],
				[
					'page_id' => (int)$row->page_id,
					'source_page_id' => count( $chain ) > 1 ? $chain[count( $chain ) - 2] : null,
					'chain_hash' => sha1( implode( ',', $chain ) ),
					'chain_page_ids' => json_encode( $chain ),
					'indexed_at' => $dbw->timestamp(),
				],
				__METHOD__
			);
		}

		$dbw->delete(
			'isekai_lpacl_pending_reindex',
			[ 'root_page_id' => $rootPageId, 'reason' => $this->params['reason'] ?? 'move' ],
			__METHOD__
		);
		return true;
	}
}
