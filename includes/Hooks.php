<?php

namespace Isekai\LitePageACL;

use ApiMessage;
use Isekai\LitePageACL\Service\PageAclPermissionManager;
use Isekai\LitePageACL\Service\PageAclStore;
use JobQueueGroup;
use MediaWiki\Hook\MovePageCheckPermissionsHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\ILoadBalancer;
use WikiPage;

class Hooks implements LoadExtensionSchemaUpdatesHook,
					   GetUserPermissionsErrorsHook,
					   MovePageCheckPermissionsHook,
					   PageSaveCompleteHook,
					   PageMoveCompleteHook,
					   SidebarBeforeOutputHook {

	private PageAclPermissionManager $permissionManager;
	private PageAclStore $store;
	private ILoadBalancer $loadBalancer;
	private JobQueueGroup $jobQueueGroup;

	public function __construct(
		PageAclPermissionManager $permissionManager,
		PageAclStore $store,
		ILoadBalancer $loadBalancer,
		JobQueueGroup $jobQueueGroup
	) {
		$this->permissionManager = $permissionManager;
		$this->store = $store;
		$this->loadBalancer = $loadBalancer;
		$this->jobQueueGroup = $jobQueueGroup;
	}

	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__ ) . '/sql/';
		$type = $updater->getDB()->getType();
		foreach ( [
			'isekai_lpacl_page',
			'isekai_lpacl_page_actor',
			'isekai_lpacl_role',
			'isekai_lpacl_role_permission',
			'isekai_lpacl_participant',
			'isekai_lpacl_inherit_index',
			'isekai_lpacl_pending_reindex',
		] as $table ) {
			$updater->addExtensionTable( $table, $dir . $type . '/' . $table . '.sql' );
		}
	}

	/**
	 * @param \MediaWiki\Title\Title $title
	 * @param \MediaWiki\User\User $user
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		if ( !$title instanceof PageIdentity || !$title->canExist() ) {
			return;
		}
		if ( $title->getId() ) {
			// 检测编辑权限
			if ( $action !== 'edit' ) {
				return;
			}
			$status = $this->permissionManager->userHasPermission( $user, $title, 'edit' );
			if ( !$status->isOK() ) {
				$result = ApiMessage::create( wfMessage( 'isekai-lpacl-edit-denied' ), 'isekai-lpacl-edit-denied' );
				return false;
			}
			return;
		}
		if ( $title->isSubpage() && ( $action === 'create' || $action === 'edit' ) ) {
			// 检测创建子页面权限
			$status = $this->permissionManager->userCanCreateSubpageAtTarget( $user, $title );
			if ( !$status->isOK() ) {
				$result = ApiMessage::create(
					wfMessage( 'isekai-lpacl-create-subpage-denied' ),
					'isekai-lpacl-create-subpage-denied'
				);
				return false;
			}
		}
	}

	public function onMovePageCheckPermissions( $oldTitle, $newTitle, $user, $reason, $status ) {
		if ( !$newTitle instanceof PageIdentity || !$newTitle->canExist() ) {
			return;
		}
		$createStatus = $this->permissionManager->userCanCreateSubpageAtTarget( $user, $newTitle );
		if ( !$createStatus->isOK() ) {
			$status->fatal( 'isekai-lpacl-move-create-subpage-denied', $newTitle->getPrefixedText() );
			return false;
		}
	}

	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$title = $skin->getTitle();
		if ( !$title || !$title->canExist() || !$title->getId() ) {
			return;
		}

		$status = $this->permissionManager->userHasPermission( $skin->getUser(), $title, 'manage' );
		if ( !$status->isOK() ) {
			return;
		}

		$sidebar['TOOLBOX']['isekai-lpacl-pageacl'] = [
			'id' => 't-isekai-lpacl-pageacl',
			'href' => SpecialPage::getTitleFor( 'IsekaiLitePageACL', $title->getPrefixedText() )->getLocalURL(),
			'text' => $skin->msg( 'isekai-lpacl-sidebar-edit' )->text(),
			'single-id' => 'isekai-lpacl-pageacl',
		];
	}

	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		if ( $wikiPage instanceof WikiPage ) {
			$page = $wikiPage->getTitle();
			$this->store->markParticipant( $page, $user, 'editor' );
			if ( $revisionRecord && $revisionRecord->getParentId() === 0 ) {
				$this->store->markParticipant( $page, $user, 'creator' );
			}
		}
	}

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$requestedAt = $dbw->timestamp();
		$inserted = $dbw->insert(
			'isekai_lpacl_pending_reindex',
			[
				'root_page_id' => (int)$pageid,
				'root_namespace' => $new->getNamespace(),
				'root_title' => $new->getDBkey(),
				'reason' => 'move',
				'requested_at' => $requestedAt,
			],
			__METHOD__,
			[ 'IGNORE' ]
		);
		if ( $inserted ) {
			$job = new Job\RebuildInheritanceIndexJob( [
				'root_page_id' => (int)$pageid,
				'root_namespace' => $new->getNamespace(),
				'root_title' => $new->getDBkey(),
				'reason' => 'move',
				'requested_at' => $requestedAt,
			] );
			$this->jobQueueGroup->push( $job );
		}
	}
}
