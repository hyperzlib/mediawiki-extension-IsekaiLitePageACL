<?php

namespace Isekai\LitePageACL\Service;

use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Registration\ExtensionRegistry;

class PermissionDefinitionRegistry {
	private const ATTRIBUTE_NAME = 'IsekaiLitePageACLPermissions';

	private Config $config;

	/** @var array<string,array>|null */
	private ?array $definitions = null;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @return array<string,array>
	 */
	public function getDefinitions(): array {
		if ( $this->definitions === null ) {
			$this->definitions = $this->loadDefinitions();
		}
		return $this->definitions;
	}

	public function hasPermission( string $permission ): bool {
		return isset( $this->getDefinitions()[$permission] );
	}

	public function getDefinition( string $permission ): ?array {
		return $this->getDefinitions()[$permission] ?? null;
	}

	public function isGrantableByPageManager( string $permission ): bool {
		$definition = $this->getDefinition( $permission );
		return (bool)( $definition['page_creator_grantable'] ?? false );
	}

	public function isGrantableByAdmin( string $permission ): bool {
		$definition = $this->getDefinition( $permission );
		return (bool)( $definition['admin_grantable'] ?? false );
	}

	/**
	 * @param string[] $permissions
	 * @return string[]
	 */
	public function expandImpliedPermissions( array $permissions ): array {
		$definitions = $this->getDefinitions();
		$resolved = [];
		$stack = array_values( array_unique( $permissions ) );
		while ( $stack ) {
			$permission = array_pop( $stack );
			if ( isset( $resolved[$permission] ) || !isset( $definitions[$permission] ) ) {
				continue;
			}
			$resolved[$permission] = true;
			foreach ( $definitions[$permission]['implies'] as $implied ) {
				$stack[] = $implied;
			}
		}
		return array_keys( $resolved );
	}

	/**
	 * @return array<string,array>
	 */
	private function loadDefinitions(): array {
		$raw = ExtensionRegistry::getInstance()->getAttribute( self::ATTRIBUTE_NAME );
		if ( !$raw && $this->config->has( self::ATTRIBUTE_NAME ) ) {
			$raw = $this->config->get( self::ATTRIBUTE_NAME );
		}
		if ( !is_array( $raw ) ) {
			return [];
		}

		$definitions = [];
		foreach ( $raw as $key => $definition ) {
			if ( !is_string( $key ) || !preg_match( '/^[a-z][a-z0-9_-]{0,127}$/', $key ) ) {
				throw new InvalidArgumentException( "Invalid IsekaiLitePageACL permission key: $key" );
			}
			$definitions[$key] = [
				'key' => $key,
				'label' => (string)( $definition['label'] ?? $key ),
				'help' => (string)( $definition['help'] ?? '' ),
				'sort' => (int)( $definition['sort'] ?? 1000 ),
				'page_creator_grantable' => (bool)( $definition['page_creator_grantable'] ?? false ),
				'admin_grantable' => (bool)( $definition['admin_grantable'] ?? false ),
				'default_grants' => [
					'creator' => (bool)( $definition['default_grants']['creator'] ?? false ),
					'editor' => (bool)( $definition['default_grants']['editor'] ?? false ),
					'user' => (bool)( $definition['default_grants']['user'] ?? false ),
				],
				'implies' => array_values( array_unique( array_map( 'strval', $definition['implies'] ?? [] ) ) ),
			];
		}

		foreach ( $definitions as $key => $definition ) {
			foreach ( $definition['implies'] as $implied ) {
				if ( !isset( $definitions[$implied] ) ) {
					throw new InvalidArgumentException( "Permission $key implies unknown permission $implied" );
				}
			}
			$this->assertNoCycle( $key, $definitions );
		}

		uasort( $definitions, static function ( array $a, array $b ) {
			return ( $a['sort'] <=> $b['sort'] ) ?: strcmp( $a['key'], $b['key'] );
		} );

		return $definitions;
	}

	private function assertNoCycle( string $key, array $definitions ): void {
		$seen = [];
		$stack = [ $key ];
		while ( $stack ) {
			$current = array_pop( $stack );
			foreach ( $definitions[$current]['implies'] as $implied ) {
				if ( $implied === $key ) {
					throw new InvalidArgumentException( "Permission imply cycle detected at $key" );
				}
				if ( !isset( $seen[$implied] ) ) {
					$seen[$implied] = true;
					$stack[] = $implied;
				}
			}
		}
	}
}
