<?php

namespace Isekai\LitePageACL\Api;

use ApiQuery;
use ApiQueryBase;
use MediaWiki\MediaWikiServices;

class ApiQueryIsekaiLpaclConfig extends ApiQueryBase {
	public function __construct( ApiQuery $query, string $moduleName ) {
		parent::__construct( $query, $moduleName, 'ipacfg' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$permissionRegistry = $services->getService( 'IsekaiLitePageACL.PermissionDefinitionRegistry' );
		$roleStore = $services->getService( 'IsekaiLitePageACL.RoleStore' );
		$permissionManager = $services->getPermissionManager();
		$user = $this->getUser();
		$this->getResult()->addValue(
			[ 'query', $this->getModuleName() ],
			null,
			[
				'permissions' => array_values( $permissionRegistry->getDefinitions() ),
				'roles' => array_values( $roleStore->getRoles() ),
				'can_manage_roles' => $permissionManager->userHasRight( $user, 'isekai-lpacl-role-admin' ),
				'is_admin' => $permissionManager->userHasRight( $user, 'isekai-lpacl-admin' ),
			]
		);
	}

	public function getCacheMode( $params ): string {
		return 'private';
	}
}
