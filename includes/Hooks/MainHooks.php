<?php

namespace Isekai\LitePageACL\Hooks;

use ApiMessage;
use Isekai\LitePageACL\Job\RebuildInheritanceIndexJob;
use Isekai\LitePageACL\Service\PageAclPermissionManager;
use Isekai\LitePageACL\Service\PageAclStore;
use Isekai\LitePageACL\Service\RoleStore;
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

class MainHooks implements GetUserPermissionsErrorsHook,
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

	/**
	 * @param \MediaWiki\Title\Title $title
	 * @param \MediaWiki\User\User $user
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		if ( !$title instanceof PageIdentity || !$title->canExist() ) {
			return;
		}
		if ( $title->getId() ) {
			// 已存在的页面，检测编辑和移动权限
			if ( $action === 'edit' ) {
				$status = $this->permissionManager->userCanEditPage( $user, $title, $title->isSubpage() );
				if ( !$status->isOK() ) {
					$result = ApiMessage::create( wfMessage( 'isekai-lpacl-edit-denied' ), 'isekai-lpacl-edit-denied' );
					return false;
				}
				return;
			}
			if ( $action === 'move' ) {
				$status = $this->permissionManager->userCanMovePage( $user, $title, $title->isSubpage() );
				if ( !$status->isOK() ) {
					$result = ApiMessage::create( wfMessage( 'isekai-lpacl-move-denied' ), 'isekai-lpacl-move-denied' );
					return false;
				}
				return;
			}
		}
		if ( $title->isSubpage() && ( $action === 'create' || $action === 'edit' ) ) {
			// 仅检测创建子页面的权限，父页面的创建权限由 MediaWiki 权限决定
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
		if ( !$oldTitle instanceof PageIdentity || !$newTitle instanceof PageIdentity || !$oldTitle->canExist() || !$newTitle->canExist() ) {
			return;
		}
		$moveStatus = $this->permissionManager->userCanMovePage( $user, $oldTitle, $oldTitle->isSubpage() );
		if ( !$moveStatus->isOK() ) {
			$status->fatal( 'isekai-lpacl-move-denied', $oldTitle->getPrefixedText() );
			return false;
		}
		if ( $newTitle->isSubpage() ) {
			$createStatus = $this->permissionManager->userCanCreateSubpageAtTarget( $user, $newTitle );
			if ( !$createStatus->isOK() ) {
				$status->fatal( 'isekai-lpacl-move-create-subpage-denied', $newTitle->getPrefixedText() );
				return false;
			}
		}
	}

	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$title = $skin->getTitle();
		if ( !$title || !$title->canExist() || !$title->getId() ) {
			return;
		}

		$status = $this->permissionManager->userHasPermission( $skin->getUser(), $title, 'grant' );
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
			$job = new RebuildInheritanceIndexJob( [
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
