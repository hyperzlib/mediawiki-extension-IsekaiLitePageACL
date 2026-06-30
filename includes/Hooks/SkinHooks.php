<?php
namespace Isekai\LitePageACL\Hooks;

use MediaWiki\MediaWikiServices;
use SpecialPage;

class SkinHooks {
    public static function onSidebarBeforeOutput( $skin, &$sidebar ): void {
        $services = MediaWikiServices::getInstance();

		$title = $skin->getTitle();
		if ( !$title || !$title->canExist() || !$title->getId() ) {
			return;
		}

        $permissionManager = $services->getService('IsekaiLitePageACL.PermissionManager');
		$status = $permissionManager->userHasPermission( $skin->getUser(), $title, 'grant' );
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
}