<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes T3 upgrader runs through core's `WP_Upgrader` lock.
 *
 * Plugin/theme install, update, and delete must not overlap. This wraps core's
 * static `WP_Upgrader::create_lock()` / `release_lock()` (available since WordPress
 * 4.5) under a single named lock so a second concurrent WebMCP upgrade fails fast
 * with a 409 instead of corrupting an in-progress operation.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.4.0
 */
final class UpgraderLock {

	/**
	 * The shared lock name for T3 upgrader runs.
	 *
	 * @var string
	 */
	private const LOCK = 'abilities_catalog_t3_update';

	/**
	 * Acquires the shared upgrader lock.
	 *
	 * @return bool|\WP_Error True when the lock was acquired, or a 409 error when
	 *                        another update already holds it.
	 */
	public static function acquire() {
		AdminIncludes::load( 'class-wp-upgrader' );

		if ( ! \WP_Upgrader::create_lock( self::LOCK ) ) {
			return new \WP_Error(
				'abilities_catalog_update_locked',
				__( 'Another update is already in progress. Try again in a moment.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Releases the shared upgrader lock.
	 *
	 * @return void
	 */
	public static function release(): void {
		AdminIncludes::load( 'class-wp-upgrader' );
		\WP_Upgrader::release_lock( self::LOCK );
	}
}
