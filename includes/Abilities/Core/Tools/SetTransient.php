<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Tools;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `tools/set-transient`.
 *
 * Stores or overwrites a single transient (a cached value with an optional
 * expiry) by name. Wraps core `set_transient()` for a normal transient, or
 * `set_site_transient()` when `network` is true (a site/network transient on
 * multisite). The `key` is the transient name WITHOUT the internal
 * `_transient_` / `_site_transient_` storage prefix that core adds. The stored
 * transient is clearable with `tools/delete-transient`.
 *
 * Value typing: a transient value is arbitrary (a scalar, array, or
 * serializable structure), so the `value` field is a JSON-type union and is
 * stored as-is. A value that is not cleanly JSON/PHP-serializable (e.g. a live
 * object graph or a resource) may not round-trip.
 *
 * Success signal — read back, do not trust the raw bool: core's
 * `set_transient()` ultimately calls `update_option()`, which returns `false`
 * when the new value EQUALS the value already stored. A raw `false` from
 * `set_transient()` therefore does NOT reliably mean failure (re-setting the
 * same value is a real "false" that is actually fine). So {@see self::execute()}
 * reads the transient back after writing and reports `stored` from the
 * read-back: `false !== get_transient( $key )` (or the site variant). This
 * makes re-setting an unchanged value correctly report `stored: true`.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it stores data).
 * - `destructive` is false: a transient is a cache entry, not a source of
 *   truth. Overwriting it is not irreversible data loss — the value is derived
 *   and self-healing — so the boolean is declared (a write must declare it) and
 *   set to false, matching `tools/delete-transient`.
 * - `idempotent` is true: setting the same key, value, and expiry twice leaves
 *   the same end state.
 *
 * It is NOT `dangerous`: this touches a single named transient, not a wide
 * site-wide blast (unlike `tools/flush-object-cache`).
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for a single
 * transient, so there is nothing for a consumer to deep-link.
 *
 * Security note: `set_transient()` / `set_site_transient()` perform NO
 * capability check of their own. The `permission_callback` plus the explicit
 * `current_user_can( 'manage_options' )` check at the top of
 * {@see self::execute()} are the only authorization guards. `manage_options` is
 * the coarse guard because transients are site infrastructure.
 *
 * @since 0.5.0
 */
final class SetTransient implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'tools/set-transient';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Set Transient', 'abilities-catalog' ),
			'description'         => __( 'Stores or overwrites a single transient (a cached value) by name, with an optional expiry. Pass the transient name without the internal "_transient_"/"_site_transient_" prefix. expiration is in seconds; 0 (the default) means no expiry (the transient persists until it is deleted or the cache evicts it). Set network to true to store a site/network transient instead. The value is stored as-is and may be any JSON type. Clear it later with tools/delete-transient. Returns stored, confirmed by reading the value back.', 'abilities-catalog' ),
			'category'            => 'tools',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'key', 'value' ),
				'properties'           => array(
					'key'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The transient name, without the internal "_transient_"/"_site_transient_" prefix that WordPress adds when storing it. For example, pass "my_cache" not "_transient_my_cache".', 'abilities-catalog' ),
					),
					'value'      => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The value to store, kept as-is. Any JSON type is accepted (string, number, boolean, object, array, or null). WordPress serializes it on save; a value that is not cleanly serializable (e.g. a live object graph) may not round-trip.', 'abilities-catalog' ),
					),
					'expiration' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Seconds until the transient expires. 0 (the default) means no expiry: the transient persists until it is deleted or the cache evicts it.', 'abilities-catalog' ),
					),
					'network'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to store a site/network transient (set_site_transient) instead of a normal transient (set_transient). Defaults to false. On a single site both stores behave the same; on multisite a site transient is shared across the network, so writing one requires network-admin (manage_network_options) capability.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'stored', 'key', 'expiration', 'network' ),
				'properties'           => array(
					'stored'     => array(
						'type'        => 'boolean',
						'description' => __( 'True if the value is now present in the transient store (confirmed by a read-back). Re-setting the same value still reports true.', 'abilities-catalog' ),
					),
					'key'        => array(
						'type'        => 'string',
						'description' => __( 'The transient name that was targeted, echoed back (without the internal storage prefix).', 'abilities-catalog' ),
					),
					'expiration' => array(
						'type'        => 'integer',
						'description' => __( 'The expiry in seconds that was applied. 0 means no expiry.', 'abilities-catalog' ),
					),
					'network'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a site/network transient was targeted (set_site_transient) instead of a normal one.', 'abilities-catalog' ),
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
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage options.
	 *
	 * Transients are site infrastructure, so `manage_options` is the baseline
	 * guard. Core's `set_transient()` / `set_site_transient()` check nothing, so
	 * this callback and the matching check in {@see self::execute()} are the only
	 * authorization. The check is object-independent — there is no per-transient
	 * capability in core — so nothing is deferred to a wrapped route.
	 *
	 * A site/network transient (`network` true) is shared across the whole network
	 * on multisite, so writing it requires the network-admin capability
	 * `manage_network_options` there — a per-site `manage_options` admin must not
	 * write cross-site network state. On a single site both resolve to `manage_options`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user holds the capability for the targeted store.
	 */
	public function hasPermission( $input = null ): bool {
		$input   = is_array( $input ) ? $input : array();
		$network = ! empty( $input['network'] );

		return current_user_can( $network && is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Executes the ability by storing the named transient.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here,
	 * at the top and before the write, because the wrapped core functions perform
	 * no capability check of their own. After writing, the value is read back and
	 * `stored` reports on that read-back (not on the raw `set_transient()` bool,
	 * which is `false` when the value is unchanged).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The set result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input      = is_array( $input ) ? $input : array();
		$key        = isset( $input['key'] ) ? (string) $input['key'] : '';
		$value      = $input['value'] ?? null;
		$expiration = isset( $input['expiration'] ) ? (int) $input['expiration'] : 0;
		$network    = ! empty( $input['network'] );

		// A network transient targets network-wide state on multisite, so it requires
		// the network-admin capability there; a single named transient needs manage_options.
		$capability = $network && is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_options',
				__( 'You are not allowed to set transients.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		if ( $network ) {
			set_site_transient( $key, $value, $expiration );
			$stored = false !== get_site_transient( $key );
		} else {
			set_transient( $key, $value, $expiration );
			$stored = false !== get_transient( $key );
		}

		return array(
			'stored'     => $stored,
			'key'        => $key,
			'expiration' => $expiration,
			'network'    => $network,
		);
	}
}
