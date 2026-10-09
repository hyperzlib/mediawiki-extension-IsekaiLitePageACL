<?php

namespace Isekai\LitePageACL\Api;

use ApiBase;
use Isekai\LitePageACL\Service\PageAclVersionConflictException;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class ApiIsekaiPageAcl extends ApiBase {
	public function execute(): void {
		$params = $this->extractRequestParams();
		$action = $params['lpaclaction'];
		$services = MediaWikiServices::getInstance();
		$titleFactory = $services->getTitleFactory();
		$manager = $services->getService( 'IsekaiLitePageACL.PermissionManager' );
		$store = $services->getService( 'IsekaiLitePageACL.PageAclStore' );
		$roleStore = $services->getService( 'IsekaiLitePageACL.RoleStore' );
		$permissionRegistry = $services->getService( 'IsekaiLitePageACL.PermissionDefinitionRegistry' );
		$actorNormalization = $services->getActorNormalization();
		$dbw = $services->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		if ( in_array( $action, [ 'setrole', 'disablerole' ], true ) ) {
			if ( !$services->getPermissionManager()->userHasRight( $this->getUser(), 'isekai-lpacl-role-admin' ) ) {
				$this->dieWithError( 'apierror-isekai-lpacl-permissiondenied', 'permissiondenied' );
			}
			if ( $action === 'disablerole' ) {
				$roleStore->disableRole( $params['lpaclrolekey'] );
				$this->getResult()->addValue( null, $this->getModuleName(), [ 'result' => 'Success' ] );
				return;
			}
			$permissions = $this->decodeJsonArray( $params['lpaclpermissions'] ?? '[]' );
			foreach ( $permissions as $permission ) {
				if ( !$permissionRegistry->hasPermission( (string)$permission ) ) {
					$this->dieWithError( 'apierror-isekai-lpacl-badpermission', 'badpermission' );
				}
			}
			$role = $roleStore->saveRole(
				$params['lpaclrolekey'],
				$params['lpaclroledescription'] ?? '',
				$permissions,
				$actorNormalization->acquireActorId( $this->getUser(), $dbw )
			);
			$this->getResult()->addValue( null, $this->getModuleName(), [ 'result' => 'Success', 'role' => $role ] );
			return;
		}

		$title = null;
		if ( $params['lpaclpageid'] ) {
			$title = $manager->getTitleFromPageId( (int)$params['lpaclpageid'] );
		} elseif ( $params['lpacltitle'] ) {
			$title = $titleFactory->newFromText( $params['lpacltitle'] );
		}
		if ( !$title || !$title->canExist() ) {
			$this->dieWithError( 'apierror-isekai-lpacl-badpage', 'badpage' );
		}
		if ( !$title->getId() ) {
			$this->dieWithError( 'apierror-isekai-lpacl-missingpage', 'missingpage' );
		}

		if ( $action === 'setpageacl' ) {
			$status = $manager->userHasPermission( $this->getUser(), $title, 'grant' );
			if ( !$status->isOK() ) {
				$this->dieWithError( 'apierror-isekai-lpacl-permissiondenied', 'permissiondenied' );
			}
			$grants = $this->normalizeGrants( $params['lpaclgrants'] ?? '[]', $manager, $title, $roleStore, $permissionRegistry );
			try {
				$data = $store->savePageAcl(
					$title,
					$this->getUser(),
					(bool)$params['lpaclinherit'],
					$grants,
					$params['lpaclversion'] !== null ? (int)$params['lpaclversion'] : null
				);
			} catch ( PageAclVersionConflictException $e ) {
				$this->dieWithError( 'apierror-isekai-lpacl-editconflict', 'editconflict' );
			}
			$this->getResult()->addValue( null, $this->getModuleName(), [ 'result' => 'Success', 'acl' => $data->toArray() ] );
			return;
		}

		$this->dieWithError( 'apierror-isekai-lpacl-unknownaction', 'unknownaction' );
	}

	private function normalizeGrants( string $json, $manager, $title, $roleStore, $permissionRegistry ): array {
		$grants = $this->decodeJsonArray( $json );
		$normalized = [];
		foreach ( $grants as $grant ) {
			$actorId = (int)( $grant['actor_id'] ?? 0 );
			if ( $actorId <= 0 ) {
				$this->dieWithError( 'apierror-isekai-lpacl-badactor', 'badactor' );
			}
			foreach ( $grant['roles'] ?? [] as $roleKey ) {
				$role = $roleStore->getRoleByKey( (string)$roleKey );
				if ( !$role ) {
					$this->dieWithError( 'apierror-isekai-lpacl-badrole', 'badrole' );
				}
				if ( !$role['enabled'] ) {
					$this->dieWithError( 'apierror-isekai-lpacl-disabledrole', 'disabledrole' );
				}
			}
			foreach ( $grant['permissions'] ?? [] as $permission ) {
				if ( !$permissionRegistry->hasPermission( (string)$permission ) ) {
					$this->dieWithError( 'apierror-isekai-lpacl-badpermission', 'badpermission' );
				}
				if ( !$manager->assertCanGrant( $this->getUser(), $title, (string)$permission )->isOK() ) {
					$this->dieWithError( 'apierror-isekai-lpacl-permissionnotgrantable', 'permissionnotgrantable' );
				}
			}
			$normalized[] = [
				'actor_id' => $actorId,
				'roles' => array_values( array_unique( array_map( 'strval', $grant['roles'] ?? [] ) ) ),
				'permissions' => array_values( array_unique( array_map( 'strval', $grant['permissions'] ?? [] ) ) ),
			];
		}
		return $normalized;
	}

	private function decodeJsonArray( string $json ): array {
		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$this->dieWithError( 'apierror-isekai-lpacl-invalidjson', 'invalidjson' );
		}
		return $data;
	}

	public function needsToken(): string {
		return 'csrf';
	}

	public function mustBePosted(): bool {
		return true;
	}

	public function isWriteMode(): bool {
		return true;
	}

	public function getAllowedParams(): array {
		return [
			'lpaclaction' => [
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_TYPE => [ 'setpageacl', 'setrole', 'disablerole' ],
			],
			'lpaclpageid' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'lpacltitle' => null,
			'lpaclversion' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'lpaclinherit' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'lpaclgrants' => null,
			'lpaclrolekey' => null,
			'lpaclroledescription' => null,
			'lpaclpermissions' => null,
		];
	}
}
