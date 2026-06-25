<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Tools;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `og-tools/flush-object-cache`.
 *
 * Flushes the ENTIRE WordPress object cache — every group, site-wide. Wraps core
 * `wp_cache_flush()` (which calls `$wp_object_cache->flush()`) and reports whether a
 * persistent external backend is active via `wp_using_ext_object_cache()`, so the
 * agent knows whether the blast cleared a shared store (Redis/Memcached) or only the
 * in-memory cache scoped to the current request.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it clears stored cache data).
 * - `destructive` is false: the object cache is not a source of truth — it is
 *   self-healing and repopulates lazily on the next reads — so flushing it is not
 *   irreversible data loss. (It is a write, so the boolean must still be declared,
 *   which is why it is present and set to false.)
 * - `idempotent` is true: flushing an already-flushed cache leaves the same end
 *   state (an empty cache).
 * - `dangerous` is true: the blast radius is WIDE — it clears ALL cached data
 *   site-wide, so on a persistent backend a busy site can see a temporary surge of
 *   cache misses and a brief performance dip. There is no `Support/` guard (no
 *   filesystem/source/upgrader/option-allow-list risk class applies — the cron
 *   precedent: operational-risk dangerous ops need none); the hard guard is
 *   `manage_options` plus the explicit check at the top of {@see self::execute()}.
 *   The Registry auto-lists any `dangerous` ability in the
 *   `abilities_catalog_dangerous_tools` filter.
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for the object
 * cache, so there is nothing for a consumer to deep-link.
 *
 * Security note: core's `wp_cache_flush()` performs NO capability check of its own.
 * The `permission_callback` plus the explicit `current_user_can( 'manage_options' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 * `manage_options` is the coarse guard because flushing all cache is site-wide
 * infrastructure.
 *
 * This is a no-input ability: it declares a bare `input_schema`, and both
 * {@see self::hasPermission()} and {@see self::execute()} default their `$input`
 * parameter to null because the Abilities API invokes them with zero arguments when
 * the ability is called with no input.
 *
 * @since 0.6.0
 */
final class FlushObjectCache implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-tools/flush-object-cache';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Flush Object Cache', 'abilities-catalog' ),
			'description'         => __( 'Flushes the ENTIRE WordPress object cache — every group, site-wide. On a persistent backend (Redis/Memcached) this empties the whole shared store; cached data repopulates lazily on the next reads, so a busy site may see a brief performance dip. On multisite the cache is shared across the network, so this requires network-admin (manage_network_options) capability there. Use to clear stale cache after out-of-band changes. Not undoable (but self-healing). Takes no input.', 'abilities-catalog' ),
			'category'            => 'og-core-tools',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'flushed', 'persistent' ),
				'properties'           => array(
					'flushed'    => array(
						'type'        => 'boolean',
						'description' => __( 'True if the object cache was flushed successfully. This is the success signal.', 'abilities-catalog' ),
					),
					'persistent' => array(
						'type'        => 'boolean',
						'description' => __( 'True if a persistent external object cache is active (the flush cleared a shared store); false means an in-memory cache scoped to the request.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
					'dangerous'   => true,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage options.
	 *
	 * This is the hard server-side guard. Flushing the whole object cache is site-wide
	 * infrastructure, so `manage_options` is the baseline. Core's `wp_cache_flush()`
	 * checks nothing, so this callback and the matching check in
	 * {@see self::execute()} are the only authorization. The check is
	 * object-independent — there is no per-cache capability in core.
	 *
	 * The `$input` parameter defaults to null: this is a no-input ability, and the
	 * Abilities API invokes this callback with zero arguments.
	 *
	 * @param mixed $input The validated input data (none for this ability).
	 * @return bool True if the current user may manage options.
	 */
	public function hasPermission( $input = null ): bool {
		// On multisite the object cache backend is shared network-wide, so a flush
		// requires the network-admin capability; on a single site it is manage_options.
		return current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Executes the ability by flushing the entire object cache.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here, at
	 * the top and before the flush, because the wrapped core function performs no
	 * capability check of its own.
	 *
	 * The `$input` parameter defaults to null: this is a no-input ability, and the
	 * Abilities API invokes execute() with zero arguments.
	 *
	 * @param mixed $input The validated input data (none for this ability).
	 * @return array<string,bool>|\WP_Error The flush result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_options',
				__( 'You are not allowed to flush the object cache.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$flushed = wp_cache_flush();

		return array(
			'flushed'    => (bool) $flushed,
			'persistent' => (bool) wp_using_ext_object_cache(),
		);
	}
}
