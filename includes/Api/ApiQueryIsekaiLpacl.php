<?php

namespace Isekai\LitePageACL\Api;

use ApiQuery;
use ApiQueryBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiQueryIsekaiLpacl extends ApiQueryBase {
	public function __construct( ApiQuery $query, string $moduleName ) {
		parent::__construct( $query, $moduleName, 'ipacl' );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$props = array_flip( $params['prop'] );
		$services = MediaWikiServices::getInstance();
		$store = $services->getService( 'IsekaiLitePageACL.PageAclStore' );
		$manager = $services->getService( 'IsekaiLitePageACL.PermissionManager' );
		$pageSet = $this->getPageSet();
		foreach ( $pageSet->getGoodPages() as $pageId => $page ) {
			$local = $store->getLocalAclForPage( $page );
			$data = [];
			if ( isset( $props['local'] ) ) {
				$data['local'] = $local->toArray();
			}
			if ( isset( $props['effective'] ) ) {
				$data['effective'] = array_values( $manager->getEffectiveAcl( $page ) );
			}
			if ( isset( $props['inherit'] ) ) {
				$data['inherit'] = $local->inherit;
				$data['chain_page_ids'] = $manager->getParentChainPageIds( $page );
			}
			if ( isset( $props['version'] ) ) {
				$data['acl_version'] = $local->aclVersion;
			}
			$this->getResult()->addValue( [ 'query', 'pages', (int)$pageId ], $this->getModuleName(), $data );
		}
	}

	public function getAllowedParams(): array {
		return [
			'prop' => [
				ParamValidator::PARAM_DEFAULT => 'local|inherit|version',
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_TYPE => [ 'local', 'effective', 'inherit', 'version' ],
			],
		];
	}

	public function getCacheMode( $params ): string {
		return 'private';
	}
}
