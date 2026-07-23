<?php

namespace Isekai\LitePageACL\Service;

use Isekai\LitePageACL\Model\PermissionDecision;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\ILoadBalancer;

class PageAclPermissionManager {

	private PermissionDefinitionRegistry $permissionRegistry;
	private RoleStore $roleStore;
	private PageAclStore $pageAclStore;
	private ILoadBalancer $loadBalancer;
	private ActorNormalization $actorNormalization;
	private HookContainer $hookContainer;
	private PermissionManager $permissionManager;
	private TitleFactory $titleFactory;
	private NamespaceInfo $namespaceInfo;

	/** @var array<int,array<int>> */
	private array $parentChainCache = [];

	/** @var array<int,array<int,string[]>> */
	private array $effectivePermissionCache = [];

	public function __construct(
		PermissionDefinitionRegistry $permissionRegistry,
		RoleStore $roleStore,
		PageAclStore $pageAclStore,
		ILoadBalancer $loadBalancer,
		ActorNormalization $actorNormalization,
		HookContainer $hookContainer,
		PermissionManager $permissionManager,
		TitleFactory $titleFactory,
		NamespaceInfo $namespaceInfo
	) {
		$this->permissionRegistry = $permissionRegistry;
		$this->roleStore = $roleStore;
		$this->pageAclStore = $pageAclStore;
		$this->loadBalancer = $loadBalancer;
		$this->actorNormalization = $actorNormalization;
		$this->hookContainer = $hookContainer;
		$this->permissionManager = $permissionManager;
		$this->titleFactory = $titleFactory;
		$this->namespaceInfo = $namespaceInfo;
	}

	public function userHasPermission(
		UserIdentity $user,
		PageIdentity $page,
		string $permission
	): PermissionStatus {
		$status = PermissionStatus::newEmpty();
		if ( !$this->permissionRegistry->hasPermission( $permission ) ) {
			$status->fatal( 'apierror-isekai-lpacl-badpermission' );
			return $status;
		}
		if ( $this->isAdmin( $user ) ) {
			return $status;
		}
		if ( !$user->isRegistered() || !$page->canExist() || !$page->getId() ) {
			$status->fatal( 'apierror-isekai-lpacl-permissiondenied' );
			return $status;
		}
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$actorId = $this->actorNormalization->findActorId( $user, $dbr );
		if ( !$actorId ) {
			$status->fatal( 'apierror-isekai-lpacl-permissiondenied' );
			return $status;
		}

		$permissions = $this->getEffectivePermissionsForActor( $actorId, $page );
		$decision = new PermissionDecision( in_array( $permission, $permissions, true ) );
		$this->hookContainer->run( 'IsekaiLpaclUserCan', [ $user, $page, $permission, $decision ] );
		if ( !$decision->isAllowed() ) {
			$status->fatal( $decision->getReason() ?: 'apierror-isekai-lpacl-permissiondenied' );
		}
		return $status;
	}

	public function userCanCreateSubpageAtTarget( UserIdentity $user, PageIdentity $target ): PermissionStatus {
		return $this->userHasParentPermissionAtTarget(
			$user,
			$target,
			'create-subpage',
			'isekai-lpacl-create-subpage-denied',
			true
		);
	}

	public function userCanEditPage( UserIdentity $user, PageIdentity $page, bool $isSubPage ): PermissionStatus {
		if ( !$isSubPage ) {
			return $this->userHasPermission( $user, $page, 'edit' );
		}
		return $this->userHasPermissionCascading(
			$user,
			$page,
			'edit',
			'edit-subpage',
			'isekai-lpacl-edit-denied',
			false
		);
	}

	public function userCanMovePage( UserIdentity $user, PageIdentity $page, bool $isSubPage ): PermissionStatus {
		if ( !$isSubPage ) {
			return $this->userHasPermission( $user, $page, 'move' );
		}
		return $this->userHasPermissionCascading(
			$user,
			$page,
			'move',
			'move-subpage',
			'isekai-lpacl-move-denied',
			false
		);
	}

	/**
	 * Check self permission first, then fall back to a different parent permission.
	 *
	 * @param UserIdentity $user
	 * @param PageIdentity $page
	 * @param string $selfPermission Permission to check on the page itself.
	 * @param string $parentPermission Permission to check on the nearest existing parent.
	 * @param string $deniedMessage i18n key when both checks fail.
	 * @param bool $allowUserDefaultWithoutParent If true and no parent exists, allow
	 *   registered users when the parent permission has default_grants.user = true.
	 * @return PermissionStatus
	 */
	public function userHasPermissionCascading(
		UserIdentity $user,
		PageIdentity $page,
		string $selfPermission,
		string $parentPermission,
		string $deniedMessage,
		bool $allowUserDefaultWithoutParent = false
	): PermissionStatus {
		$status = $this->userHasPermission( $user, $page, $selfPermission );
		if ( $status->isOK() ) {
			return $status;
		}
		return $this->userHasParentPermissionAtTarget(
			$user,
			$page,
			$parentPermission,
			$deniedMessage,
			$allowUserDefaultWithoutParent
		);
	}

	/**
	 * Check whether the user has the same permission on the page itself or the
	 * nearest existing parent.
	 *
	 * @param UserIdentity $user
	 * @param PageIdentity $page
	 * @param string $permission Permission to check on self and parent.
	 * @param string $deniedMessage i18n key when both checks fail.
	 * @param bool $allowUserDefaultWithoutParent If true and no parent exists, allow
	 *   registered users when the permission has default_grants.user = true.
	 * @return PermissionStatus
	 */
	public function userHasPermissionOrParent(
		UserIdentity $user,
		PageIdentity $page,
		string $permission,
		string $deniedMessage,
		bool $allowUserDefaultWithoutParent = false
	): PermissionStatus {
		return $this->userHasPermissionCascading(
			$user,
			$page,
			$permission,
			$permission,
			$deniedMessage,
			$allowUserDefaultWithoutParent
		);
	}

	/**
	 * @return array<int,array{actor_id:int,roles:string[],permissions:string[]}>
	 */
	public function getEffectiveAcl( PageIdentity $page ): array {
		$effective = [];
		foreach ( $this->getAclChain( $page ) as $acl ) {
			if ( !$acl->inherit ) {
				$effective = $acl->grants;
			} else {
				foreach ( $acl->grants as $actorId => $grant ) {
					$effective[$actorId] = $grant;
				}
			}
		}
		return $effective;
	}

	/**
	 * @return string[]
	 */
	public function getEffectivePermissionsForActor( int $actorId, PageIdentity $page ): array {
		$pageId = $page->getId();
		if ( isset( $this->effectivePermissionCache[$pageId][$actorId] ) ) {
			return $this->effectivePermissionCache[$pageId][$actorId];
		}

		$permissions = [];
		foreach ( $this->getDefaultPermissionsForActor( $actorId, $page ) as $permission ) {
			$permissions[$permission] = true;
		}
		$grant = $this->getEffectiveAcl( $page )[$actorId] ?? null;
		if ( $grant ) {
			foreach ( $grant['permissions'] as $permission ) {
				$permissions[$permission] = true;
			}
			foreach ( $this->roleStore->expandRoleKeys( $grant['roles'] ) as $permission ) {
				$permissions[$permission] = true;
			}
		}
		$expanded = $this->permissionRegistry->expandImpliedPermissions( array_keys( $permissions ) );
		$this->effectivePermissionCache[$pageId][$actorId] = $expanded;
		return $expanded;
	}

	public function assertCanGrant( UserIdentity $performer, PageIdentity $page, string $permission ) {
		if ( !$this->permissionRegistry->hasPermission( $permission ) ) {
			return \Status::newFatal( 'apierror-isekai-lpacl-badpermission' );
		}
		if ( $this->isAdmin( $performer ) ) {
			return $this->permissionRegistry->isGrantableByAdmin( $permission )
				? \Status::newGood()
				: \Status::newFatal( 'apierror-isekai-lpacl-permissionnotgrantable' );
		}
		$status = $this->userHasPermission( $performer, $page, 'grant' );
		if ( !$status->isOK() ) {
			return \Status::newFatal( 'apierror-isekai-lpacl-permissiondenied' );
		}
		return $this->permissionRegistry->isGrantableByPageManager( $permission )
			? \Status::newGood()
			: \Status::newFatal( 'apierror-isekai-lpacl-permissionnotgrantable' );
	}

	public function isAdmin( UserIdentity $user ): bool {
		return $this->permissionManager->userHasRight( $user, 'isekai-lpacl-admin' );
	}

	/**
	 * @return PageAclData[]
	 */
	private function getAclChain( PageIdentity $page ): array {
		$chainPageIds = $this->getParentChainPageIds( $page );
		$chain = [];
		foreach ( $chainPageIds as $pageId ) {
			$chain[] = $this->pageAclStore->getLocalAclForPageId( $pageId );
		}
		return $chain;
	}

	/**
	 * @return int[]
	 */
	public function getParentChainPageIds( PageIdentity $page ): array {
		$pageId = $page->getId();
		if ( isset( $this->parentChainCache[$pageId] ) ) {
			return $this->parentChainCache[$pageId];
		}
		$ids = [];
		if ( !$this->namespaceInfo->hasSubpages( $page->getNamespace() ) ) {
			return $this->parentChainCache[$pageId] = [ $pageId ];
		}
		$dbKey = $page->getDBkey();
		$parts = explode( '/', $dbKey );
		$titles = [];
		for ( $i = 1; $i <= count( $parts ); $i++ ) {
			$titles[] = implode( '/', array_slice( $parts, 0, $i ) );
		}
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		foreach ( $titles as $dbKeyPart ) {
			$id = (int)$dbr->selectField(
				'page',
				'page_id',
				[ 'page_namespace' => $page->getNamespace(), 'page_title' => $dbKeyPart ],
				__METHOD__
			);
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( !in_array( $pageId, $ids, true ) ) {
			$ids[] = $pageId;
		}
		return $this->parentChainCache[$pageId] = $ids;
	}

	/**
	 * @return string[]
	 */
	private function getDefaultPermissionsForActor( int $actorId, PageIdentity $page ): array {
		$defaults = [];
		foreach ( $this->permissionRegistry->getDefinitions() as $permission => $definition ) {
			$grant = $definition['default_grants'];
			if (
				( $grant['user'] ?? false ) ||
				( ( $grant['creator'] ?? false ) && $this->actorIsCreatorInPageChain( $page, $actorId ) )
			) {
				$defaults[] = $permission;
			}
		}
		return $defaults;
	}

	private function actorIsCreatorInPageChain( PageIdentity $page, int $actorId ): bool {
		foreach ( $this->getParentChainPageIds( $page ) as $pageId ) {
			if ( $this->pageAclStore->actorHasParticipantType( $pageId, $actorId, 'creator' ) ) {
				return true;
			}
		}
		return false;
	}

	public function userHasParentPermissionAtTarget(
		UserIdentity $user,
		PageIdentity $target,
		string $permission,
		string $deniedMessage,
		bool $allowUserDefaultWithoutParent
	): PermissionStatus {
		$status = PermissionStatus::newEmpty();
		if ( !$this->permissionRegistry->hasPermission( $permission ) ) {
			$status->fatal( 'apierror-isekai-lpacl-badpermission' );
			return $status;
		}
		if ( $this->isAdmin( $user ) || !$target->canExist() ) {
			return $status;
		}

		$parentTitle = $this->getNearestExistingParentTitle( $target );
		if ( $parentTitle ) {
			return $this->userHasPermission( $user, $parentTitle, $permission );
		}

		if (
			$allowUserDefaultWithoutParent &&
			$user->isRegistered() &&
			( $this->permissionRegistry->getDefinition( $permission )['default_grants']['user'] ?? false )
		) {
			return $status;
		}

		$status->fatal( $deniedMessage );
		return $status;
	}

	public function getTitleFromPageId( int $pageId ): ?Title {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$row = $dbr->selectRow(
			'page',
			[ 'page_namespace', 'page_title' ],
			[ 'page_id' => $pageId ],
			__METHOD__
		);
		if ( !$row ) {
			return null;
		}
		return $this->titleFactory->makeTitle( (int)$row->page_namespace, (string)$row->page_title );
	}

	private function getNearestExistingParentTitle( PageIdentity $target ): ?Title {
		if ( !$this->namespaceInfo->hasSubpages( $target->getNamespace() ) ) {
			return null;
		}
		$parts = explode( '/', $target->getDBkey() );
		if ( count( $parts ) < 2 ) {
			return null;
		}

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		for ( $i = count( $parts ) - 1; $i >= 1; $i-- ) {
			$dbKey = implode( '/', array_slice( $parts, 0, $i ) );
			$pageId = (int)$dbr->selectField(
				'page',
				'page_id',
				[ 'page_namespace' => $target->getNamespace(), 'page_title' => $dbKey ],
				__METHOD__
			);
			if ( $pageId ) {
				return $this->getTitleFromPageId( $pageId );
			}
		}
		return null;
	}
}
