<?php

namespace Isekai\LitePageACL\Service;

use WANObjectCache;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;

class RoleStore {
	private const CACHE_TTL = 3600;
	private const CACHE_VERSION = 'v2';

	private ILoadBalancer $loadBalancer;
	private WANObjectCache $cache;
	private PermissionDefinitionRegistry $permissionRegistry;

	/** @var array<string,array>|null */
	private ?array $roleCache = null;

	public function __construct(
		ILoadBalancer $loadBalancer,
		WANObjectCache $cache,
		PermissionDefinitionRegistry $permissionRegistry
	) {
		$this->loadBalancer = $loadBalancer;
		$this->cache = $cache;
		$this->permissionRegistry = $permissionRegistry;
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
			[ 'role_id', 'role_key', 'role_description', 'role_permissions', 'role_enabled' ],
			[],
			__METHOD__
		);
		$roles = [];
		foreach ( $rows as $row ) {
			$roleId = (int)$row->role_id;
			$permissions = $this->decodeArrayField( $dbr->getType(), $row->role_permissions );
			$validPermissions = [];
			foreach ( $permissions as $permission ) {
				if ( $this->permissionRegistry->hasPermission( $permission ) ) {
					$validPermissions[] = $permission;
				}
			}
			$roles[(string)$row->role_key] = [
				'id' => $roleId,
				'key' => (string)$row->role_key,
				'name_message' => $this->getRoleNameMessageKey( (string)$row->role_key ),
				'description' => $row->role_description !== null ? (string)$row->role_description : '',
				'enabled' => (bool)$row->role_enabled,
				'permissions' => $validPermissions,
			];
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
			$this->updateRoleRow( $dbw, $roleId, $description, $permissions, $now );
		} else {
			$this->insertRoleRow( $dbw, $roleKey, $description, $permissions, $performerActorId, $now );
			$roleId = (int)$dbw->insertId();
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
		$deleted = $dbw->delete(
			'isekai_lpacl_role',
			[ 'role_key' => $roleKey ],
			__METHOD__
		);
		$this->roleCache = null;
		$this->cache->delete( $this->getRolesCacheKey() );
		return (bool)$deleted;
	}

	/**
	 * @return string[]
	 */
	private function decodeArrayField( string $dbType, $value ): array {
		if ( $value === null || $value === '' ) {
			return [];
		}
		if ( is_array( $value ) ) {
			return array_values( array_map( 'strval', $value ) );
		}
		if ( $dbType === 'postgres' ) {
			return $this->decodePostgresTextArray( (string)$value );
		}
		$decoded = json_decode( (string)$value, true );
		return is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : [];
	}

	/**
	 * @param string[] $permissions
	 */
	private function encodeArrayField( IDatabase $db, array $permissions ): string {
		$permissions = $this->normalizeArrayField( $permissions );
		if ( $db->getType() === 'postgres' ) {
			return '{' . implode( ',', array_map( [ $this, 'encodePostgresArrayValue' ], $permissions ) ) . '}';
		}
		return json_encode( $permissions );
	}

	private function updateRoleRow(
		IDatabase $dbw,
		int $roleId,
		string $description,
		array $permissions,
		string $now
	): void {
		if ( $dbw->getType() === 'mysql' ) {
			$table = $dbw->tableName( 'isekai_lpacl_role' );
			$permissionsJson = json_encode( $this->normalizeArrayField( $permissions ) );
			$sql = "UPDATE $table SET " .
				'role_description = ' . $dbw->addQuotes( $description ) . ', ' .
				'role_permissions = CONVERT(' . $dbw->addQuotes( $permissionsJson ) . ' USING utf8mb4), ' .
				'role_enabled = 1, ' .
				'updated_at = ' . $dbw->addQuotes( $now ) . ' ' .
				'WHERE role_id = ' . $roleId;
			$dbw->query( $sql, __METHOD__ );
			return;
		}
		$dbw->update(
			'isekai_lpacl_role',
			[
				'role_description' => $description,
				'role_permissions' => $this->encodeArrayField( $dbw, $permissions ),
				'role_enabled' => 1,
				'updated_at' => $now,
			],
			[ 'role_id' => $roleId ],
			__METHOD__
		);
	}

	private function insertRoleRow(
		IDatabase $dbw,
		string $roleKey,
		string $description,
		array $permissions,
		int $performerActorId,
		string $now
	): void {
		if ( $dbw->getType() === 'mysql' ) {
			$table = $dbw->tableName( 'isekai_lpacl_role' );
			$permissionsJson = json_encode( $this->normalizeArrayField( $permissions ) );
			$sql = "INSERT INTO $table " .
				"(role_key, role_description, role_permissions, role_enabled, created_by_actor_id, created_at, updated_at) VALUES (" .
				$dbw->addQuotes( $roleKey ) . ', ' .
				$dbw->addQuotes( $description ) . ', ' .
				'CONVERT(' . $dbw->addQuotes( $permissionsJson ) . ' USING utf8mb4), ' .
				'1, ' .
				$performerActorId . ', ' .
				$dbw->addQuotes( $now ) . ', ' .
				$dbw->addQuotes( $now ) .
				')';
			$dbw->query( $sql, __METHOD__ );
			return;
		}
		$dbw->insert(
			'isekai_lpacl_role',
			[
				'role_key' => $roleKey,
				'role_description' => $description,
				'role_permissions' => $this->encodeArrayField( $dbw, $permissions ),
				'role_enabled' => 1,
				'created_by_actor_id' => $performerActorId,
				'created_at' => $now,
				'updated_at' => $now,
			],
			__METHOD__
		);
	}

	/**
	 * @param string[] $values
	 * @return string[]
	 */
	private function normalizeArrayField( array $values ): array {
		return array_values( array_unique( array_map( 'strval', $values ) ) );
	}

	private function encodePostgresArrayValue( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}

	/**
	 * @return string[]
	 */
	private function decodePostgresTextArray( string $value ): array {
		$value = trim( $value );
		if ( $value === '{}' || $value === '' ) {
			return [];
		}
		$value = trim( $value, '{}' );
		if ( $value === '' ) {
			return [];
		}
		$items = str_getcsv( $value, ',', '"', '\\' );
		return array_values( array_map( 'strval', $items ) );
	}

	private function getRolesCacheKey(): string {
		return $this->cache->makeKey( 'isekai-lite-page-acl', 'roles', self::CACHE_VERSION );
	}

	private function getRoleNameMessageKey( string $roleKey ): string {
		return 'lpacl-role-' . $roleKey . '-name';
	}
}
