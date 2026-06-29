<?php

namespace Isekai\LitePageACL\Service;

use WANObjectCache;
use Wikimedia\Rdbms\ILoadBalancer;

class RoleStore {
	private const CACHE_TTL = 3600;
	private const CACHE_VERSION = 'v1';

	private ILoadBalancer $loadBalancer;
	private WANObjectCache $cache;

	/** @var array<string,array>|null */
	private ?array $roleCache = null;

	public function __construct( ILoadBalancer $loadBalancer, WANObjectCache $cache ) {
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
	}

	/**
	 * @return array<string,array>
	 */
	public function getRoles(): array {
		if ( $this->roleCache !== null ) {
			return $this->roleCache;
		}
		$this->roleCache = $this->cache->getWithSetCallback(
			$this->getRolesCacheKey(),
			self::CACHE_TTL,
			function () {
				return $this->loadRolesFromDatabase();
			}
		);
		return $this->roleCache;
	}

	/**
	 * @return array<string,array>
	 */
	private function loadRolesFromDatabase(): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $dbr->select(
			'isekai_lpacl_role',
			[ 'role_id', 'role_key', 'role_description', 'role_enabled' ],
			[],
			__METHOD__
		);
		$roles = [];
		foreach ( $rows as $row ) {
			$roleId = (int)$row->role_id;
			$roles[(string)$row->role_key] = [
				'id' => $roleId,
				'key' => (string)$row->role_key,
				'name_message' => $this->getRoleNameMessageKey( (string)$row->role_key ),
				'description' => $row->role_description !== null ? (string)$row->role_description : '',
				'enabled' => (bool)$row->role_enabled,
				'permissions' => [],
			];
		}
		if ( $roles ) {
			$permissions = $dbr->select(
				'isekai_lpacl_role_permission',
				[ 'role_id', 'permission' ],
				[ 'role_id' => array_column( $roles, 'id' ) ],
				__METHOD__
			);
			$byId = [];
			foreach ( $roles as $key => $role ) {
				$byId[$role['id']] = $key;
			}
			foreach ( $permissions as $row ) {
				$key = $byId[(int)$row->role_id] ?? null;
				if ( $key !== null ) {
					$roles[$key]['permissions'][] = (string)$row->permission;
				}
			}
		}
		return $roles;
	}

	public function getRoleByKey( string $key ): ?array {
		return $this->getRoles()[$key] ?? null;
	}

	public function getRoleById( int $id ): ?array {
		foreach ( $this->getRoles() as $role ) {
			if ( $role['id'] === $id ) {
				return $role;
			}
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	public function expandRoleKeys( array $roleKeys ): array {
		$permissions = [];
		foreach ( $roleKeys as $key ) {
			$role = $this->getRoleByKey( (string)$key );
			if ( $role && $role['enabled'] ) {
				foreach ( $role['permissions'] as $permission ) {
					$permissions[$permission] = true;
				}
			}
		}
		return array_keys( $permissions );
	}

	public function saveRole(
		string $roleKey,
		string $description,
		array $permissions,
		int $performerActorId
	): array {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$now = $dbw->timestamp();
		$existing = $dbw->selectRow(
			'isekai_lpacl_role',
			[ 'role_id' ],
			[ 'role_key' => $roleKey ],
			__METHOD__
		);
		if ( $existing ) {
			$roleId = (int)$existing->role_id;
			$dbw->update(
				'isekai_lpacl_role',
				[
					'role_description' => $description,
					'role_enabled' => 1,
					'updated_at' => $now,
				],
				[ 'role_id' => $roleId ],
				__METHOD__
			);
		} else {
			$dbw->insert(
				'isekai_lpacl_role',
				[
					'role_key' => $roleKey,
					'role_description' => $description,
					'role_enabled' => 1,
					'created_by_actor_id' => $performerActorId,
					'created_at' => $now,
					'updated_at' => $now,
				],
				__METHOD__
			);
			$roleId = (int)$dbw->insertId();
		}
		$dbw->delete( 'isekai_lpacl_role_permission', [ 'role_id' => $roleId ], __METHOD__ );
		foreach ( array_values( array_unique( $permissions ) ) as $permission ) {
			$dbw->insert(
				'isekai_lpacl_role_permission',
				[ 'role_id' => $roleId, 'permission' => (string)$permission ],
				__METHOD__,
				[ 'IGNORE' ]
			);
		}
		$this->roleCache = null;
		$this->cache->delete( $this->getRolesCacheKey() );
		return $this->getRoleByKey( $roleKey ) ?? [];
	}

	public function disableRole( string $roleKey ): bool {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$updated = $dbw->update(
			'isekai_lpacl_role',
			[ 'role_enabled' => 0, 'updated_at' => $dbw->timestamp() ],
			[ 'role_key' => $roleKey ],
			__METHOD__
		);
		$this->roleCache = null;
		$this->cache->delete( $this->getRolesCacheKey() );
		return (bool)$updated;
	}

	public function enableRole( string $roleKey ): bool {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$updated = $dbw->update(
			'isekai_lpacl_role',
			[ 'role_enabled' => 1, 'updated_at' => $dbw->timestamp() ],
			[ 'role_key' => $roleKey ],
			__METHOD__
		);
		$this->roleCache = null;
		$this->cache->delete( $this->getRolesCacheKey() );
		return (bool)$updated;
	}

	public function deleteRole( string $roleKey ): bool {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$row = $dbw->selectRow(
			'isekai_lpacl_role',
			[ 'role_id' ],
			[ 'role_key' => $roleKey ],
			__METHOD__
		);
		if ( !$row ) {
			return false;
		}
		$roleId = (int)$row->role_id;
		$dbw->startAtomic( __METHOD__ );
		try {
			$dbw->delete( 'isekai_lpacl_role_permission', [ 'role_id' => $roleId ], __METHOD__ );
			$deleted = $dbw->delete( 'isekai_lpacl_role', [ 'role_id' => $roleId ], __METHOD__ );
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Throwable $e ) {
			$dbw->cancelAtomic( __METHOD__ );
			throw $e;
		}
		$this->roleCache = null;
		$this->cache->delete( $this->getRolesCacheKey() );
		return (bool)$deleted;
	}

	private function getRolesCacheKey(): string {
		return $this->cache->makeKey( 'isekai-lite-page-acl', 'roles', self::CACHE_VERSION );
	}

	private function getRoleNameMessageKey( string $roleKey ): string {
		return 'lpacl-role-' . $roleKey . '-name';
	}
}
