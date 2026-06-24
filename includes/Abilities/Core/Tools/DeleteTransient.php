<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Tools;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `tools/delete-transient`.
 *
 * Deletes a single transient (a cached value with an expiry) by name so the next
 * read recomputes it. Wraps core `delete_transient()` for a normal transient, or
 * `delete_site_transient()` when `network` is true (a site/network transient on
 * multisite). The `key` is the transient name WITHOUT the internal
 * `_transient_` / `_site_transient_` storage prefix that core adds.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it removes stored data).
 * - `destructive` is false: a transient is a cache entry, not a source of truth.
 *   Removing it is self-healing — the next read recomputes the value from its
 *   source — so there is no irreversible data loss. (It is a write, so the
 *   boolean must still be declared, which is why it is present and set to false.)
 * - `idempotent` is true: deleting an absent transient is a no-op; deleting the
 *   same key twice leaves the same end state (no transient).
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for a single
 * transient, so there is nothing for a consumer to deep-link (the same reason the
 * generic option pair omits `screen`).
 *
 * Security note: `delete_transient()` / `delete_site_transient()` perform NO
 * capability check of their own. The `permission_callback` plus the explicit
 * `current_user_can( 'manage_options' )` check at the top of {@see self::execute()}
 * are the only authorization guards. `manage_options` is the coarse guard because
 * transients are site infrastructure.
 *
 * @since 0.5.0
 */
final class DeleteTransient implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'tools/delete-transient';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Transient', 'abilities-catalog' ),
			'description'         => __( 'Deletes a single transient (a cached value) by name so it is recomputed on the next read. Pass the transient name without the internal "_transient_"/"_site_transient_" prefix. Set network to true to delete a site/network transient instead. A false "deleted" result is not an error: it means no transient was removed under this key (typically because it was unset or had already expired).', 'abilities-catalog' ),
			'category'            => 'tools',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'key' ),
				'properties'           => array(
					'key'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The transient name, without the internal "_transient_"/"_site_transient_" prefix that WordPress adds when storing it. For example, pass "my_cache" not "_transient_my_cache".', 'abilities-catalog' ),
					),
					'network' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to target a site/network transient (delete_site_transient) instead of a normal transient (delete_transient). Defaults to false. On a single site both stores behave the same; on multisite a site transient is shared across the network, so deleting one requires network-admin (manage_network_options) capability.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'key' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'True if a transient was removed under this key. False means nothing was removed (typically because it was unset or had already expired). False is not an error.', 'abilities-catalog' ),
					),
					'key'     => array(
						'type'        => 'string',
						'description' => __( 'The transient name that was targeted, echoed back (without the internal storage prefix).', 'abilities-catalog' ),
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
	 * Transients are site infrastructure, so `manage_options` is the baseline guard.
	 * Core's `delete_transient()` / `delete_site_transient()` check nothing, so this
	 * callback and the matching check in {@see self::execute()} are the only
	 * authorization. The check is object-independent — there is no per-transient
	 * capability in core — so nothing is deferred to a wrapped route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage options.
	 */
	public function hasPermission( $input = null ): bool {
		$input   = is_array( $input ) ? $input : array();
		$network = ! empty( $input['network'] );

		// A site/network transient is network-wide on multisite, so deleting it
		// requires the network-admin capability there; a normal transient is per-site.
		return current_user_can( $network && is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Executes the ability by deleting the named transient.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here, at
	 * the top and before the delete, because the wrapped core functions perform no
	 * capability check of their own.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The delete result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$key     = isset( $input['key'] ) ? (string) $input['key'] : '';
		$network = ! empty( $input['network'] );

		// A network transient targets network-wide state on multisite, so it requires
		// the network-admin capability there; a normal transient needs manage_options.
		$capability = $network && is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_options',
				__( 'You are not allowed to delete transients.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$deleted = $network
			? delete_site_transient( $key )
			: delete_transient( $key );

		return array(
			'deleted' => (bool) $deleted,
			'key'     => $key,
		);
	}
}
