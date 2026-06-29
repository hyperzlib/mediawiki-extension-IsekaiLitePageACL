<?php

namespace Isekai\LitePageACL\SpecialPage;

use Isekai\LitePageACL\Model\PageAclData;
use Isekai\LitePageACL\Service\PageAclPermissionManager;
use Isekai\LitePageACL\Service\PageAclStore;
use Isekai\LitePageACL\Service\PageAclVersionConflictException;
use Isekai\LitePageACL\Service\PermissionDefinitionRegistry;
use Isekai\LitePageACL\Service\RoleStore;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use MediaWiki\Widget\UserInputWidget;
use OOUI\ButtonInputWidget;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\HtmlSnippet;
use OOUI\HorizontalLayout;
use OOUI\PanelLayout;
use OOUI\TextInputWidget;
use SpecialPage;
use Wikimedia\Rdbms\ILoadBalancer;

class SpecialIsekaiLitePageACL extends SpecialPage {
	private MediaWikiServices $services;
	private PageAclStore $store;
	private RoleStore $roleStore;
	private PageAclPermissionManager $permissionManager;
	private PermissionDefinitionRegistry $permissionRegistry;
	private ILoadBalancer $loadBalancer;

	public function __construct() {
		parent::__construct( 'IsekaiLitePageACL' );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->requireNamedUser();

		$this->services = MediaWikiServices::getInstance();
		$this->store = $this->services->getService( 'IsekaiLitePageACL.PageAclStore' );
		$this->roleStore = $this->services->getService( 'IsekaiLitePageACL.RoleStore' );
		$this->permissionManager = $this->services->getService( 'IsekaiLitePageACL.PermissionManager' );
		$this->permissionRegistry = $this->services->getService( 'IsekaiLitePageACL.PermissionDefinitionRegistry' );
		$this->loadBalancer = $this->services->getDBLoadBalancer();

		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addModules( [ 'ext.isekaiLitePageACL.special' ] );
		$out->addModuleStyles( [ 'oojs-ui.styles.icons-interactions', 'oojs-ui.styles.icons-editing-core' ] );
		$this->addHelpLink( 'Help:Page permissions' );

		$title = $this->resolveTargetTitle( $subPage );
		if ( !$title ) {
			$out->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-error-missing-target' )->escaped() ) );
			return;
		}

		$status = $this->permissionManager->userHasPermission( $this->getUser(), $title, 'manage' );
		if ( !$status->isOK() ) {
			$this->displayRestrictionError();
			return;
		}

		$out->setPageTitleMsg( $this->msg( 'isekai-lpacl-special-title', $title->getPrefixedText() ) );

		$request = $this->getRequest();
		$action = $request->getVal( 'action', 'preview' );
		$localAcl = $this->store->getLocalAclForPage( $title );
		$parentTitle = $this->getNearestExistingParentTitle( $title );

		if ( $request->wasPosted() ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ), $this->getTokenSalt( $title ) ) ) {
				$out->addHTML( Html::errorBox( $this->msg( 'sessionfailure' )->escaped() ) );
				return;
			}
			$this->checkReadOnly();
			$this->handlePost( $title, $localAcl, $parentTitle, $action );
			return;
		}

		switch ( $action ) {
			case 'add':
				$this->showAddForm( $title );
				break;
			case 'edit':
				$this->showEditForm( $title, $localAcl, $parentTitle );
				break;
			case 'delete':
				$this->showDeleteForm( $title, $localAcl );
				break;
			default:
				$this->showPreview( $title, $localAcl, $parentTitle );
		}
	}

	private function handlePost( Title $title, PageAclData $localAcl, ?Title $parentTitle, string $action ): void {
		$request = $this->getRequest();
		switch ( $action ) {
			case 'add':
				$username = trim( $request->getText( 'user' ) );
				$user = $this->findRegisteredUser( $username );
				if ( !$user ) {
					$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-error-user-not-found' )->escaped() ) );
					$this->showAddForm( $title, $username );
					return;
				}
				$this->redirectTo( $title, [ 'action' => 'edit', 'user' => $user->getName() ] );
				return;
			case 'edit':
				$this->saveEditedGrant( $title, $localAcl );
				return;
			case 'delete':
				$this->deleteGrant( $title, $localAcl );
				return;
			default:
				if ( $parentTitle ) {
					if ( !$this->saveAcl(
						$title,
						$request->getBool( 'inherit' ),
						array_values( $localAcl->grants ),
						$localAcl->aclVersion
					) ) {
						return;
					}
				}
				$this->redirectTo( $title );
		}
	}

	private function showPreview( Title $title, PageAclData $localAcl, ?Title $parentTitle ): void {
		$out = $this->getOutput();
		$out->addHTML( $this->renderTargetHeader( $title ) );
		if ( $parentTitle ) {
			$out->addHTML( $this->renderInheritancePanel( $title, $localAcl, $parentTitle ) );
		}
		$out->addHTML( $this->renderPermissionListPanel( $title, $localAcl ) );
	}

	private function showAddForm( Title $title, string $value = '' ): void {
		$userInput = new UserInputWidget( [
			'name' => 'user',
			'value' => $value,
			'infusable' => true,
			'required' => true,
			'classes' => [ 'ext-isekai-lpacl-user-input' ],
		] );
		$fieldset = new FieldsetLayout( [
			'label' => $this->msg( 'isekai-lpacl-add-user-title' )->text(),
			'items' => [
				new FieldLayout( $userInput, [
					'label' => $this->msg( 'isekai-lpacl-user-label' )->text(),
					'align' => 'top',
				] ),
			],
		] );
		$this->getOutput()->addHTML(
			$this->renderForm(
				$title,
				[ 'action' => 'add' ],
				$fieldset .
				new ButtonInputWidget( [
					'name' => 'submit',
					'label' => $this->msg( 'isekai-lpacl-submit' )->text(),
					'flags' => [ 'primary', 'progressive' ],
					'type' => 'submit',
				] )
			)
		);
	}

	private function showEditForm( Title $title, PageAclData $localAcl, ?Title $parentTitle ): void {
		$actorId = $this->getEditActorId( $title, $localAcl );
		if ( !$actorId ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-error-user-not-found' )->escaped() ) );
			return;
		}

		$grant = $localAcl->grants[$actorId] ?? [
			'actor_id' => $actorId,
			'roles' => [],
			'permissions' => [],
		];
		$actor = $this->getActorById( $actorId );
		$actorName = $actor ? $actor->getName() : (string)$actorId;
		$parentGrant = null;
		if ( $parentTitle ) {
			$parentGrant = $this->permissionManager->getEffectiveAcl( $parentTitle )[$actorId] ?? null;
		}

		$html = $this->renderTargetHeader( $title );
		if ( $parentGrant ) {
			$html .= $this->renderParentGrantPanel( $parentTitle, $parentGrant );
		}

		$roleFields = [];
		foreach ( $this->roleStore->getRoles() as $roleKey => $role ) {
			if ( empty( $role['enabled'] ) ) {
				continue;
			}
			$roleFields[] = new FieldLayout( new CheckboxInputWidget( [
				'name' => 'roles[]',
				'value' => $roleKey,
				'selected' => in_array( $roleKey, $grant['roles'], true ),
			] ), [
				'label' => $this->formatRoleLabel( $role ),
				'help' => $role['description'] ?? '',
				'align' => 'inline',
			] );
		}
		$permissionFields = [];
		foreach ( $this->permissionRegistry->getDefinitions() as $permissionKey => $definition ) {
			if ( !$this->permissionManager->assertCanGrant( $this->getUser(), $title, $permissionKey )->isOK() ) {
				continue;
			}
			$permissionFields[] = new FieldLayout( new CheckboxInputWidget( [
				'name' => 'permissions[]',
				'value' => $permissionKey,
				'selected' => in_array( $permissionKey, $grant['permissions'], true ),
			] ), [
				'label' => $this->formatPermissionLabel( $definition ),
				'help' => $this->msgIfExists( $definition['help'] ?? '' ),
				'align' => 'inline',
			] );
		}

		$fieldset = new FieldsetLayout( [
			'label' => $this->msg( 'isekai-lpacl-edit-user-title', $actorName )->text(),
			'items' => [
				new FieldLayout( new TextInputWidget( [
					'value' => $actorName,
					'readOnly' => true,
				] ), [
					'label' => $this->msg( 'isekai-lpacl-user-label' )->text(),
					'align' => 'top',
				] ),
				new FieldsetLayout( [
					'label' => $this->msg( 'isekai-lpacl-roles-label' )->text(),
					'items' => $roleFields,
				] ),
			],
		] );

		$permissionDetails = Html::rawElement(
			'details',
			[ 'class' => 'ext-isekai-lpacl-permission-details' ],
			Html::element( 'summary', [], $this->msg( 'isekai-lpacl-permissions-label' )->text() ) .
			new FieldsetLayout( [ 'items' => $permissionFields ] )
		);

		$html .= $this->renderForm(
			$title,
			$this->getRequest()->getVal( 'id' ) !== null
				? [ 'action' => 'edit', 'id' => (string)$actorId ]
				: [ 'action' => 'edit', 'user' => $actorName ],
			Html::hidden( 'actorId', (string)$actorId ) .
			$fieldset .
			$permissionDetails .
			new HorizontalLayout( [
				'items' => [
					new ButtonInputWidget( [
						'name' => 'submit',
						'label' => $this->msg( 'isekai-lpacl-save' )->text(),
						'flags' => [ 'primary', 'progressive' ],
						'type' => 'submit',
					] ),
					new ButtonWidget( [
						'label' => $this->msg( 'cancel' )->text(),
						'href' => $this->getPageAclUrl( $title ),
					] ),
				],
			] )
		);
		$this->getOutput()->addHTML( $html );
	}

	private function showDeleteForm( Title $title, PageAclData $localAcl ): void {
		$actorId = $this->getRequest()->getInt( 'id' );
		$grant = $localAcl->grants[$actorId] ?? null;
		if ( !$grant ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-error-grant-not-found' )->escaped() ) );
			return;
		}
		$actor = $this->getActorById( $actorId );
		$actorName = $actor ? $actor->getName() : (string)$actorId;
		$panel = new PanelLayout( [
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'content' => new HtmlSnippet(
				Html::element( 'h2', [], $this->msg( 'isekai-lpacl-delete-title' )->text() ) .
				Html::element( 'p', [], $this->msg( 'isekai-lpacl-delete-confirm', $actorName )->text() ) .
				$this->renderGrantTable( [ $actorId => $grant ], [] )
			),
		] );
		$this->getOutput()->addHTML(
			$this->renderForm(
				$title,
				[ 'action' => 'delete', 'id' => (string)$actorId ],
				$panel .
				new HorizontalLayout( [
					'items' => [
						new ButtonInputWidget( [
							'name' => 'confirm',
							'label' => $this->msg( 'isekai-lpacl-delete' )->text(),
							'flags' => [ 'primary', 'destructive' ],
							'type' => 'submit',
						] ),
						new ButtonWidget( [
							'label' => $this->msg( 'cancel' )->text(),
							'href' => $this->getPageAclUrl( $title ),
						] ),
					],
				] )
			)
		);
	}

	private function saveEditedGrant( Title $title, PageAclData $localAcl ): void {
		$request = $this->getRequest();
		$actorId = $request->getInt( 'actorId' );
		if ( !$this->getActorById( $actorId ) ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-error-user-not-found' )->escaped() ) );
			return;
		}

		$roles = array_values( array_intersect(
			array_keys( $this->roleStore->getRoles() ),
			array_map( 'strval', $request->getArray( 'roles', [] ) )
		) );
		$permissions = [];
		foreach ( array_map( 'strval', $request->getArray( 'permissions', [] ) ) as $permission ) {
			if (
				$this->permissionRegistry->hasPermission( $permission ) &&
				$this->permissionManager->assertCanGrant( $this->getUser(), $title, $permission )->isOK()
			) {
				$permissions[] = $permission;
			}
		}

		$grants = $localAcl->grants;
		$grants[$actorId] = [
			'actor_id' => $actorId,
			'roles' => array_values( array_unique( $roles ) ),
			'permissions' => array_values( array_unique( $permissions ) ),
		];
		if ( $this->saveAcl( $title, $localAcl->inherit, array_values( $grants ), $localAcl->aclVersion ) ) {
			$this->redirectTo( $title );
		}
	}

	private function deleteGrant( Title $title, PageAclData $localAcl ): void {
		$actorId = $this->getRequest()->getInt( 'id' );
		$grants = $localAcl->grants;
		unset( $grants[$actorId] );
		if ( $this->saveAcl( $title, $localAcl->inherit, array_values( $grants ), $localAcl->aclVersion ) ) {
			$this->redirectTo( $title );
		}
	}

	private function saveAcl( Title $title, bool $inherit, array $grants, int $expectedVersion ): bool {
		try {
			$this->store->savePageAcl( $title, $this->getUser(), $inherit, $grants, $expectedVersion );
		} catch ( PageAclVersionConflictException $e ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'apierror-isekai-lpacl-editconflict' )->escaped() ) );
			return false;
		}
		return true;
	}

	private function renderTargetHeader( Title $title ): string {
		return Html::rawElement(
			'p',
			[ 'class' => 'ext-isekai-lpacl-target' ],
			$this->msg( 'isekai-lpacl-target-page', $title->getPrefixedText() )->parse()
		);
	}

	private function renderInheritancePanel( Title $title, PageAclData $localAcl, Title $parentTitle ): string {
		$checkbox = new CheckboxInputWidget( [
			'name' => 'inherit',
			'value' => '1',
			'selected' => $localAcl->inherit,
		] );
		$content = new FieldLayout( $checkbox, [
			'label' => $this->msg( 'isekai-lpacl-inherit-parent' )->text(),
			'align' => 'inline',
		] );
		if ( $localAcl->inherit ) {
			$content .= Html::rawElement(
				'details',
				[ 'class' => 'ext-isekai-lpacl-parent-acl' ],
				Html::element( 'summary', [], $this->msg( 'isekai-lpacl-parent-permissions' )->text() ) .
				$this->renderGrantTable(
					$this->permissionManager->getEffectiveAcl( $parentTitle ),
					array_keys( $localAcl->grants )
				)
			);
		}
		$content .= new ButtonInputWidget( [
			'name' => 'submit',
			'label' => $this->msg( 'isekai-lpacl-submit' )->text(),
			'flags' => [ 'primary', 'progressive' ],
			'type' => 'submit',
		] );

		return $this->renderForm(
			$title,
			[],
			new PanelLayout( [
				'expanded' => false,
				'padded' => true,
				'framed' => true,
				'classes' => [ 'ext-isekai-lpacl-panel' ],
				'content' => new HtmlSnippet( $content ),
			] )
		);
	}

	private function renderPermissionListPanel( Title $title, PageAclData $localAcl ): string {
		$heading = Html::element( 'h2', [], $this->msg( 'isekai-lpacl-permission-list' )->text() );
		$addButton = new ButtonWidget( [
			'label' => $this->msg( 'isekai-lpacl-add' )->text(),
			'icon' => 'add',
			'flags' => [ 'progressive' ],
			'href' => $this->getPageAclUrl( $title, [ 'action' => 'add' ] ),
		] );
		$header = Html::rawElement(
			'div',
			[ 'class' => 'ext-isekai-lpacl-panel-header' ],
			$heading . $addButton
		);
		return (string)new PanelLayout( [
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'classes' => [ 'ext-isekai-lpacl-panel' ],
			'content' => new HtmlSnippet( $header . $this->renderGrantTable( $localAcl->grants, [], $title ) ),
		] );
	}

	private function renderParentGrantPanel( Title $parentTitle, array $grant ): string {
		return (string)new PanelLayout( [
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'classes' => [ 'ext-isekai-lpacl-panel' ],
			'content' => new HtmlSnippet(
				Html::element( 'h2', [], $this->msg( 'isekai-lpacl-parent-grant-title' )->text() ) .
				$this->renderGrantTable( [ (int)$grant['actor_id'] => $grant ], [] ) .
				Html::element(
					'p',
					[ 'class' => 'ext-isekai-lpacl-help' ],
					$this->msg( 'isekai-lpacl-parent-grant-help', $parentTitle->getPrefixedText() )->text()
				)
			),
		] );
	}

	private function renderGrantTable( array $grants, array $overriddenActorIds = [], ?Title $title = null ): string {
		if ( !$grants ) {
			return Html::element( 'p', [ 'class' => 'mw-muted' ], $this->msg( 'isekai-lpacl-empty-grants' )->text() );
		}
		$overridden = array_fill_keys( array_map( 'intval', $overriddenActorIds ), true );
		$rows = '';
		foreach ( $grants as $grant ) {
			$actorId = (int)$grant['actor_id'];
			$actor = $this->getActorById( $actorId );
			$rowAttrs = isset( $overridden[$actorId] ) ? [ 'class' => 'ext-isekai-lpacl-overridden' ] : [];
			$actions = '';
			if ( $title ) {
				$actions = new ButtonWidget( [
					'label' => $this->msg( 'edit' )->text(),
					'icon' => 'edit',
					'framed' => false,
					'href' => $this->getPageAclUrl( $title, [ 'action' => 'edit', 'id' => (string)$actorId ] ),
				] );
				$actions .= new ButtonWidget( [
					'label' => $this->msg( 'delete' )->text(),
					'icon' => 'trash',
					'framed' => false,
					'flags' => [ 'destructive' ],
					'href' => $this->getPageAclUrl( $title, [ 'action' => 'delete', 'id' => (string)$actorId ] ),
				] );
			}
			$rows .= Html::rawElement(
				'tr',
				$rowAttrs,
				Html::element( 'td', [], $actor ? $actor->getName() : (string)$actorId ) .
				Html::element( 'td', [], $this->formatRoleKeys( $grant['roles'] ?? [] ) ) .
				Html::element( 'td', [], $this->formatPermissionKeys( $grant['permissions'] ?? [] ) ) .
				Html::element( 'td', [], $this->formatPermissionKeys( $this->roleStore->expandRoleKeys( $grant['roles'] ?? [] ) ) ) .
				Html::rawElement( 'td', [], $actions )
			);
		}
		return Html::rawElement(
			'table',
			[ 'class' => 'wikitable ext-isekai-lpacl-grant-table' ],
			Html::rawElement(
				'thead',
				[],
				Html::rawElement(
					'tr',
					[],
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-user-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-roles-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-direct-permissions-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-role-permissions-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-actions-label' )->text() )
				)
			) .
			Html::rawElement( 'tbody', [], $rows )
		);
	}

	private function renderForm( Title $title, array $query, string $content ): string {
		return Html::rawElement(
			'form',
			[
				'method' => 'post',
				'action' => $this->getPageAclUrl( $title, $query ),
			'class' => 'ext-isekai-lpacl-form',
			],
			Html::hidden( 'wpEditToken', $this->getUser()->getEditToken( $this->getTokenSalt( $title ) ) ) .
			$content
		);
	}

	private function resolveTargetTitle( ?string $subPage ): ?Title {
		$subPage = trim( (string)$subPage );
		if ( $subPage === '' ) {
			return null;
		}
		$title = $this->services->getTitleFactory()->newFromText( $subPage );
		if ( !$title || !$title->canExist() || !$title->getId() ) {
			return null;
		}
		return $title;
	}

	private function getNearestExistingParentTitle( Title $title ): ?Title {
		if ( !$this->services->getNamespaceInfo()->hasSubpages( $title->getNamespace() ) ) {
			return null;
		}
		$parts = explode( '/', $title->getDBkey() );
		if ( count( $parts ) < 2 ) {
			return null;
		}
		for ( $i = count( $parts ) - 1; $i >= 1; $i-- ) {
			$parent = Title::makeTitleSafe( $title->getNamespace(), implode( '/', array_slice( $parts, 0, $i ) ) );
			if ( $parent && $parent->getId() ) {
				return $parent;
			}
		}
		return null;
	}

	private function getEditActorId( Title $title, PageAclData $localAcl ): ?int {
		$request = $this->getRequest();
		if ( $request->getVal( 'id' ) !== null ) {
			$actorId = $request->getInt( 'id' );
			return $actorId > 0 ? $actorId : null;
		}
		$username = trim( $request->getText( 'user' ) );
		$user = $this->findRegisteredUser( $username );
		if ( !$user ) {
			return null;
		}
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $this->services->getActorNormalization()->acquireActorId( $user, $dbw );
	}

	private function findRegisteredUser( string $username ): ?UserIdentity {
		if ( $username === '' ) {
			return null;
		}
		$user = $this->services->getUserIdentityLookup()->getUserIdentityByName( $username );
		if ( !$user || !$user->isRegistered() ) {
			return null;
		}
		return $user;
	}

	private function getActorById( int $actorId ): ?UserIdentity {
		if ( $actorId <= 0 ) {
			return null;
		}
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		return $this->services->getActorStore()->getActorById( $actorId, $dbr );
	}

	private function redirectTo( Title $title, array $query = [] ): void {
		$this->getOutput()->redirect( $this->getPageAclUrl( $title, $query ) );
	}

	private function getPageAclUrl( Title $title, array $query = [] ): string {
		return SpecialPage::getTitleFor( 'IsekaiLitePageACL', $title->getPrefixedText() )->getLocalURL( $query );
	}

	private function getTokenSalt( Title $title ): string {
		return 'isekai-lpacl-' . $title->getId();
	}

	private function formatRoleKeys( array $roleKeys ): string {
		$roles = $this->roleStore->getRoles();
		$labels = [];
		foreach ( $roleKeys as $roleKey ) {
			$labels[] = isset( $roles[$roleKey] ) ? $this->formatRoleLabel( $roles[$roleKey] ) : (string)$roleKey;
		}
		return $labels ? implode( ', ', $labels ) : $this->msg( 'isekai-lpacl-none' )->text();
	}

	private function formatPermissionKeys( array $permissionKeys ): string {
		$labels = [];
		foreach ( $permissionKeys as $permissionKey ) {
			$definition = $this->permissionRegistry->getDefinition( (string)$permissionKey );
			$labels[] = $definition ? $this->formatPermissionLabel( $definition ) : (string)$permissionKey;
		}
		return $labels ? implode( ', ', $labels ) : $this->msg( 'isekai-lpacl-none' )->text();
	}

	private function formatRoleLabel( array $role ): string {
		$roleKey = (string)( $role['key'] ?? '' );
		$messageKey = (string)( $role['name_message'] ?? $this->getRoleNameMessageKey( $roleKey ) );
		$msg = $this->msg( $messageKey );
		return $msg->exists() ? $msg->text() : $this->getRoleNameFallback( $roleKey );
	}

	private function getRoleNameMessageKey( string $roleKey ): string {
		return 'lpacl-role-' . $roleKey . '-name';
	}

	private function getRoleNameFallback( string $roleKey ): string {
		return str_replace( '-', '', $roleKey );
	}

	private function formatPermissionLabel( array $definition ): string {
		$messageKey = (string)( $definition['label'] ?? '' );
		return $this->msgIfExists( $messageKey ) ?: (string)( $definition['key'] ?? $messageKey );
	}

	private function msgIfExists( string $messageKey ): string {
		if ( $messageKey === '' ) {
			return '';
		}
		$msg = $this->msg( $messageKey );
		return $msg->exists() ? $msg->text() : $messageKey;
	}
}
