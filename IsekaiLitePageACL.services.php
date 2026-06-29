<?php

use Isekai\LitePageACL\Service\PageAclPermissionManager;
use Isekai\LitePageACL\Service\PageAclStore;
use Isekai\LitePageACL\Service\PermissionDefinitionRegistry;
use Isekai\LitePageACL\Service\RoleStore;
use MediaWiki\MediaWikiServices;

return [
	'IsekaiLitePageACL.PermissionDefinitionRegistry' => static function ( MediaWikiServices $services ) {
		return new PermissionDefinitionRegistry( $services->getMainConfig() );
	},
	'IsekaiLitePageACL.RoleStore' => static function ( MediaWikiServices $services ) {
		return new RoleStore(
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->getService( 'IsekaiLitePageACL.PermissionDefinitionRegistry' )
		);
	},
	'IsekaiLitePageACL.PageAclStore' => static function ( MediaWikiServices $services ) {
		return new PageAclStore(
			$services->getDBLoadBalancer(),
			$services->getActorNormalization()
		);
	},
	'IsekaiLitePageACL.PermissionManager' => static function ( MediaWikiServices $services ) {
		return new PageAclPermissionManager(
			$services->getService( 'IsekaiLitePageACL.PermissionDefinitionRegistry' ),
			$services->getService( 'IsekaiLitePageACL.RoleStore' ),
			$services->getService( 'IsekaiLitePageACL.PageAclStore' ),
			$services->getDBLoadBalancer(),
			$services->getActorNormalization(),
			$services->getHookContainer(),
			$services->getPermissionManager(),
			$services->getTitleFactory(),
			$services->getNamespaceInfo()
		);
	},
];
