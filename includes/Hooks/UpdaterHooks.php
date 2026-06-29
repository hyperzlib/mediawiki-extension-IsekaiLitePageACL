<?php
namespace Isekai\LitePageACL\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\MediaWikiServices;

class UpdaterHooks implements LoadExtensionSchemaUpdatesHook {

	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( dirname( __DIR__ ) ) . '/sql/';
		$type = $updater->getDB()->getType();
		foreach ( [
			'isekai_lpacl_page',
			'isekai_lpacl_page_actor',
			'isekai_lpacl_role',
			'isekai_lpacl_participant',
			'isekai_lpacl_inherit_index',
			'isekai_lpacl_pending_reindex',
		] as $table ) {
			$updater->addExtensionTable( $table, $dir . $type . '/' . $table . '.sql' );
		}
		$updater->addExtensionUpdate( [
			[ self::class, 'initDefaultRoles' ]
		] );
	}

	public static function initDefaultRoles( $updater ): bool {
		$dbw = $updater->getDB();
		$count = (int)$dbw->selectField(
			'isekai_lpacl_role',
			'COUNT(*)',
			[],
			__METHOD__
		);
		if ( $count > 0 ) {
			return true;
		}
		self::seedDefaultRoles();
		return true;
	}

	private static function seedDefaultRoles(): void {
		$defaultRoles = [
			'page-admin' => [ 'edit', 'move', 'grant', 'create-subpage', 'edit-subpage', 'move-subpage' ],
			'page-editor' => [ 'edit', 'create-subpage', 'edit-subpage', 'move-subpage' ],
			'page-contributor' => [ 'edit', 'create-subpage', 'edit-subpage' ],
			'subpage-editor' => [ 'create-subpage', 'edit-subpage', 'move-subpage' ],
			'subpage-author' => [ 'create-subpage', 'edit-subpage' ],
		];
		$roleStore = MediaWikiServices::getInstance()->getService( 'IsekaiLitePageACL.RoleStore' );
		foreach ( $defaultRoles as $key => $permissions ) {
			$roleStore->saveRole( $key, '', $permissions, 0 );
		}
	}
}
