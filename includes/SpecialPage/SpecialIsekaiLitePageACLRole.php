<?php

namespace Isekai\LitePageACL\SpecialPage;

use Isekai\LitePageACL\Service\PermissionDefinitionRegistry;
use Isekai\LitePageACL\Service\RoleStore;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use OOUI\ButtonInputWidget;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\HorizontalLayout;
use OOUI\HtmlSnippet;
use OOUI\MultilineTextInputWidget;
use OOUI\PanelLayout;
use OOUI\TextInputWidget;
use OOUI\Widget;
use SpecialPage;

class SpecialIsekaiLitePageACLRole extends SpecialPage {
	private RoleStore $roleStore;
	private PermissionDefinitionRegistry $permissionRegistry;

	public function __construct() {
		parent::__construct( 'IsekaiLitePageACLRole', 'isekai-lpacl-role-admin' );
	}

	public function doesWrites() {
		return true;
	}

	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->requireNamedUser();
		$this->checkPermissions();

		$services = MediaWikiServices::getInstance();
		$this->roleStore = $services->getService( 'IsekaiLitePageACL.RoleStore' );
		$this->permissionRegistry = $services->getService( 'IsekaiLitePageACL.PermissionDefinitionRegistry' );

		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addModules( [ 'ext.isekaiLitePageACL.special' ] );
		$out->addModuleStyles( [ 'oojs-ui.styles.icons-interactions', 'oojs-ui.styles.icons-editing-core' ] );
		$out->setPageTitleMsg( $this->msg( 'isekai-lpacl-role-special-title' ) );

		$request = $this->getRequest();
		$action = $request->getVal( 'action', 'list' );
		if ( $request->wasPosted() ) {
			if ( !$this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ), $this->getTokenSalt() ) ) {
				$out->addHTML( Html::errorBox( $this->msg( 'sessionfailure' )->escaped() ) );
				return;
			}
			$this->checkReadOnly();
			$this->handlePost( $action );
			return;
		}

		switch ( $action ) {
			case 'add':
				$this->showEditForm();
				break;
			case 'edit':
				$this->showEditForm( $request->getText( 'role' ) );
				break;
			case 'disable':
				$this->showDisableForm( $request->getText( 'role' ) );
				break;
			case 'enable':
				$this->showEnableForm( $request->getText( 'role' ) );
				break;
			case 'delete':
				$this->showDeleteForm( $request->getText( 'role' ) );
				break;
			default:
				$this->showList();
		}
	}

	private function handlePost( string $action ): void {
		switch ( $action ) {
			case 'add':
			case 'edit':
				$this->saveRole();
				return;
			case 'disable':
				$this->disableRole();
				return;
			case 'enable':
				$this->enableRole();
				return;
			case 'delete':
				$this->deleteRole();
				return;
			default:
				$this->redirectToList();
		}
	}

	private function showList(): void {
		$roles = $this->roleStore->getRoles();
		$addButton = new ButtonWidget( [
			'label' => $this->msg( 'isekai-lpacl-role-add' )->text(),
			'icon' => 'add',
			'flags' => [ 'progressive' ],
			'href' => $this->getPageUrl( [ 'action' => 'add' ] ),
		] );
		$header = Html::rawElement(
			'div',
			[ 'class' => 'ext-isekai-lpacl-panel-header' ],
			Html::element( 'h2', [], $this->msg( 'isekai-lpacl-role-list-title' )->text() ) . $addButton
		);
		$this->getOutput()->addHTML( $header . $this->renderRoleTable( $roles ) );
	}

	private function showEditForm( ?string $roleKey = null, array $submitted = [], bool $forceAdd = false ): void {
		$isEdit = $roleKey !== null && $roleKey !== '';
		if ( $forceAdd ) {
			$isEdit = false;
			$roleKey = null;
		}
		$role = $isEdit ? $this->roleStore->getRoleByKey( $roleKey ) : null;
		if ( $isEdit && !$role ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-role-error-not-found' )->escaped() ) );
			$this->showList();
			return;
		}

		$values = [
			'key' => $submitted['key'] ?? ( $role['key'] ?? '' ),
			'description' => $submitted['description'] ?? ( $role['description'] ?? '' ),
			'permissions' => $submitted['permissions'] ?? ( $role['permissions'] ?? [] ),
		];

		$fields = [
			new FieldLayout( new TextInputWidget( [
				'name' => 'roleKey',
				'value' => $values['key'],
				'readOnly' => $isEdit,
				'required' => true,
			] ), [
				'label' => $this->msg( 'isekai-lpacl-role-key-label' )->text(),
				'help' => $this->msg( 'isekai-lpacl-role-key-help' )->text(),
				'align' => 'top',
			] ),
			new FieldLayout( new MultilineTextInputWidget( [
				'name' => 'roleDescription',
				'value' => $values['description'],
				'rows' => 3,
			] ), [
				'label' => $this->msg( 'isekai-lpacl-role-description-label' )->text(),
				'align' => 'top',
			] ),
			new FieldLayout( new Widget( [
				'content' => [ new FieldsetLayout( [
					'label' => $this->msg( 'isekai-lpacl-role-permissions-title' )->text(),
					'items' => $this->buildPermissionFields( $values['permissions'] ),
					'class' => 'isekai-lpacl-permission-field',
				] ) ],
			] ), [
				'align' => 'top',
			] ),
		];

		$formTitle = $isEdit
			? $this->msg( 'isekai-lpacl-role-edit-title', $values['key'] )->text()
			: $this->msg( 'isekai-lpacl-role-add-title' )->text();
		$fieldset = new FieldsetLayout( [
			'label' => $formTitle,
			'items' => $fields,
		] );
		$query = $isEdit ? [ 'action' => 'edit', 'role' => $values['key'] ] : [ 'action' => 'add' ];
		$nameMessageHelp = $values['key'] !== '' ? $this->renderRoleNameMessageHelp( $values['key'] ) : '';
		$this->getOutput()->addHTML(
			$this->renderForm(
				$query,
				$fieldset .
				$nameMessageHelp .
				new FieldLayout( new Widget( [
					'content' => [ new HorizontalLayout( [
						'items' => [
							new ButtonInputWidget( [
								'name' => 'submit',
								'label' => $this->msg( 'isekai-lpacl-save' )->text(),
								'flags' => [ 'primary', 'progressive' ],
								'type' => 'submit',
							] ),
							new ButtonWidget( [
								'label' => $this->msg( 'cancel' )->text(),
								'href' => $this->getPageUrl(),
							] ),
						],
					] ) ],
				] ), [
					'align' => 'top',
				] )
			)
		);
	}

	private function showDisableForm( string $roleKey ): void {
		$this->showRoleConfirmForm(
			$roleKey,
			'disable',
			'isekai-lpacl-role-disable-title',
			'isekai-lpacl-role-disable-confirm',
			'isekai-lpacl-role-disable',
			[ 'primary', 'destructive' ]
		);
	}

	private function showEnableForm( string $roleKey ): void {
		$this->showRoleConfirmForm(
			$roleKey,
			'enable',
			'isekai-lpacl-role-enable-title',
			'isekai-lpacl-role-enable-confirm',
			'isekai-lpacl-role-enable',
			[ 'primary', 'progressive' ]
		);
	}

	private function showDeleteForm( string $roleKey ): void {
		$this->showRoleConfirmForm(
			$roleKey,
			'delete',
			'isekai-lpacl-role-delete-title',
			'isekai-lpacl-role-delete-confirm',
			'isekai-lpacl-role-delete',
			[ 'primary', 'destructive' ]
		);
	}

	private function showRoleConfirmForm(
		string $roleKey,
		string $action,
		string $titleMessage,
		string $confirmMessage,
		string $buttonMessage,
		array $buttonFlags
	): void {
		$role = $this->roleStore->getRoleByKey( $roleKey );
		if ( !$role ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-role-error-not-found' )->escaped() ) );
			$this->showList();
			return;
		}
		$panel = new PanelLayout( [
			'expanded' => false,
			'padded' => true,
			'framed' => true,
			'classes' => [ 'ext-isekai-lpacl-panel' ],
			'content' => new HtmlSnippet(
				Html::element( 'h2', [], $this->msg( $titleMessage )->text() ) .
				Html::element(
					'p',
					[],
					$this->msg( $confirmMessage, $this->formatRoleLabel( $role ) )->text()
				) .
				$this->renderRoleTable( [ $role['key'] => $role ], false )
			),
		] );
		$this->getOutput()->addHTML(
			$this->renderForm(
				[ 'action' => $action, 'role' => $role['key'] ],
				$panel .
				new FieldLayout( new Widget( [
					'content' => [ new HorizontalLayout( [
						'items' => [
							new ButtonInputWidget( [
								'name' => 'confirm',
								'label' => $this->msg( $buttonMessage )->text(),
								'flags' => $buttonFlags,
								'type' => 'submit',
							] ),
							new ButtonWidget( [
								'label' => $this->msg( 'cancel' )->text(),
								'href' => $this->getPageUrl(),
							] ),
						],
					] ) ],
				] ), [
					'align' => 'top',
				] )
			)
		);
	}

	private function saveRole(): void {
		$request = $this->getRequest();
		$roleKey = trim( $request->getText( 'roleKey' ) );
		$description = trim( $request->getText( 'roleDescription' ) );
		$permissions = $this->normalizePermissions( $request->getArray( 'permissions', [] ) );
		$submitted = [
			'key' => $roleKey,
			'description' => $description,
			'permissions' => $permissions,
		];

		if ( !preg_match( '/^[a-z][a-z0-9_-]{0,127}$/', $roleKey ) ) {
			$this->getOutput()->addHTML( Html::errorBox( $this->msg( 'isekai-lpacl-role-error-bad-key' )->escaped() ) );
			$this->showEditForm(
				$this->getRequest()->getText( 'role' ),
				$submitted,
				$this->getRequest()->getVal( 'action' ) === 'add'
			);
			return;
		}

		$services = MediaWikiServices::getInstance();
		$dbw = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$actorId = $services->getActorNormalization()->acquireActorId( $this->getUser(), $dbw );
		$this->roleStore->saveRole( $roleKey, $description, $permissions, $actorId );
		$this->redirectToList();
	}

	private function disableRole(): void {
		$roleKey = $this->getRequest()->getText( 'role' );
		$this->roleStore->disableRole( $roleKey );
		$this->redirectToList();
	}

	private function enableRole(): void {
		$roleKey = $this->getRequest()->getText( 'role' );
		$this->roleStore->enableRole( $roleKey );
		$this->redirectToList();
	}

	private function deleteRole(): void {
		$roleKey = $this->getRequest()->getText( 'role' );
		$this->roleStore->deleteRole( $roleKey );
		$this->redirectToList();
	}

	private function renderRoleTable( array $roles, bool $showActions = true ): string {
		if ( !$roles ) {
			return Html::element( 'p', [ 'class' => 'mw-muted' ], $this->msg( 'isekai-lpacl-role-empty-list' )->text() );
		}
		$rows = '';
		foreach ( $roles as $role ) {
			$actions = '';
			if ( $showActions ) {
				$actions = '';
				if ( !empty( $role['enabled'] ) ) {
					$actions .= new ButtonWidget( [
						'label' => $this->msg( 'edit' )->text(),
						'icon' => 'edit',
						'framed' => false,
						'href' => $this->getPageUrl( [ 'action' => 'edit', 'role' => $role['key'] ] ),
					] );
					$actions .= new ButtonWidget( [
						'label' => $this->msg( 'isekai-lpacl-role-disable' )->text(),
						'icon' => 'trash',
						'framed' => false,
						'flags' => [ 'destructive' ],
						'href' => $this->getPageUrl( [ 'action' => 'disable', 'role' => $role['key'] ] ),
					] );
				} else {
					$actions .= new ButtonWidget( [
						'label' => $this->msg( 'isekai-lpacl-role-enable' )->text(),
						'icon' => 'check',
						'framed' => false,
						'flags' => [ 'progressive' ],
						'href' => $this->getPageUrl( [ 'action' => 'enable', 'role' => $role['key'] ] ),
					] );
					$actions .= new ButtonWidget( [
						'label' => $this->msg( 'isekai-lpacl-role-delete' )->text(),
						'icon' => 'trash',
						'framed' => false,
						'flags' => [ 'destructive' ],
						'href' => $this->getPageUrl( [ 'action' => 'delete', 'role' => $role['key'] ] ),
					] );
				}
			}
			$rows .= Html::rawElement(
				'tr',
				empty( $role['enabled'] ) ? [ 'class' => 'ext-isekai-lpacl-overridden' ] : [],
				Html::element( 'td', [], (string)$role['key'] ) .
				Html::element( 'td', [], $this->formatRoleLabel( $role ) ) .
				Html::element( 'td', [], (string)( $role['description'] ?? '' ) ) .
				Html::element( 'td', [], $this->formatStatus( (bool)$role['enabled'] ) ) .
				Html::element( 'td', [], $this->formatPermissionKeys( $role['permissions'] ?? [] ) ) .
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
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-role-key-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-role-preview-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-role-description-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-role-status-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-permissions-label' )->text() ) .
					Html::element( 'th', [], $this->msg( 'isekai-lpacl-actions-label' )->text() )
				)
			) .
			Html::rawElement( 'tbody', [], $rows )
		);
	}

	private function buildPermissionFields( array $selectedPermissions ): array {
		$fields = [];
		foreach ( $this->permissionRegistry->getDefinitions() as $permissionKey => $definition ) {
			$fields[] = new FieldLayout( new CheckboxInputWidget( [
				'name' => 'permissions[]',
				'value' => $permissionKey,
				'selected' => in_array( $permissionKey, $selectedPermissions, true ),
			] ), [
				'label' => $this->formatPermissionLabel( $definition ),
				'help' => $this->msgIfExists( $definition['help'] ?? '' ),
				'align' => 'inline',
			] );
		}
		return $fields;
	}

	private function renderForm( array $query, string $content ): string {
		return Html::rawElement(
			'form',
			[
				'method' => 'post',
				'action' => $this->getPageUrl( $query ),
				'class' => 'ext-isekai-lpacl-form',
			],
			Html::hidden( 'wpEditToken', $this->getUser()->getEditToken( $this->getTokenSalt() ) ) .
			$content
		);
	}

	private function normalizePermissions( array $permissions ): array {
		$normalized = [];
		foreach ( array_map( 'strval', $permissions ) as $permission ) {
			if ( $this->permissionRegistry->hasPermission( $permission ) ) {
				$normalized[$permission] = true;
			}
		}
		return array_keys( $normalized );
	}

	private function formatPermissionKeys( array $permissionKeys ): string {
		$labels = [];
		foreach ( $permissionKeys as $permissionKey ) {
			$definition = $this->permissionRegistry->getDefinition( (string)$permissionKey );
			$labels[] = $definition ? $this->formatPermissionLabel( $definition ) : (string)$permissionKey;
		}
		return $labels ? implode( ', ', $labels ) : $this->msg( 'isekai-lpacl-none' )->text();
	}

	private function formatPermissionLabel( array $definition ): string {
		$messageKey = (string)( $definition['label'] ?? '' );
		return $this->msgIfExists( $messageKey ) ?: (string)( $definition['key'] ?? $messageKey );
	}

	private function renderRoleNameMessageHelp( string $roleKey ): string {
		return (string)new FieldLayout( new Widget( [
			'content' => new HtmlSnippet(
				Html::rawElement(
					'div',
					[ 'class' => 'ext-isekai-lpacl-help' ],
					$this->msg( 'isekai-lpacl-role-name-message-help' )->escaped() . ' ' .
					$this->renderRoleNameMessageLink( $roleKey ) .
					Html::element('br') .
					Html::element(
						'span',
						[],
						$this->msg(
							'isekai-lpacl-role-name-preview',
							$this->formatRoleLabel( [ 'key' => $roleKey ] )
						)->text()
					)
				)
			),
		] ), [
			'align' => 'top',
		] );
	}

	private function renderRoleNameMessageLink( string $roleKey ): string {
		$messageKey = $this->getRoleNameMessageKey( $roleKey );
		$title = Title::makeTitleSafe( NS_MEDIAWIKI, $messageKey );
		return Html::element(
			'a',
			[ 'href' => $title ? $title->getLocalURL() : '#' ],
			$messageKey
		);
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

	private function formatStatus( bool $enabled ): string {
		return $enabled
			? $this->msg( 'isekai-lpacl-role-status-enabled' )->text()
			: $this->msg( 'isekai-lpacl-role-status-disabled' )->text();
	}

	private function msgIfExists( string $messageKey ): string {
		if ( $messageKey === '' ) {
			return '';
		}
		$msg = $this->msg( $messageKey );
		return $msg->exists() ? $msg->text() : $messageKey;
	}

	private function redirectToList(): void {
		$this->getOutput()->redirect( $this->getPageUrl() );
	}

	private function getPageUrl( array $query = [] ): string {
		return $this->getPageTitle()->getLocalURL( $query );
	}

	private function getTokenSalt(): string {
		return 'isekai-lpacl-role';
	}
}
