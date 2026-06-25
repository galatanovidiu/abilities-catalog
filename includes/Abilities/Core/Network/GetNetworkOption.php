<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core-function read ability: `og-network/get-network-option`.
 *
 * Reads a single network (site) option by name from the current network (or a
 * specific network on a multi-network install) and reports whether it exists
 * plus its current value, so an agent can inspect network-wide configuration.
 * Wraps `get_network_option()` (wp-includes/option.php:1999), which returns the
 * stored value or the supplied default when the option does not exist; a null
 * `$network_id` resolves to the current network (option.php:2009). No REST route
 * exposes network options and the function calls no wp-admin-only code, so this
 * uses the core-function idiom with no `AdminIncludes::load`.
 *
 * Existence detection: core has no separate existence check and a stored `false`
 * (or `''` or `0`) is a valid value, so this ability passes a class-private
 * random sentinel string as the default and compares it by identity
 * (`$sentinel !== $value`). A bare `false` default would mis-report a stored
 * `false` as "missing"; the random sentinel cannot collide with any stored value.
 *
 * Value typing: a network option value is arbitrary (scalar, array, or a
 * serializable structure), so the `value` field is a JSON type union returned
 * as-is and forced to null when the option does not exist. A value that is not
 * cleanly JSON-serializable (a PHP object graph) may not round-trip through the
 * tool surface.
 *
 * Multisite-only: network options live in `wp_sitemeta`, which only exists on a
 * multisite install. Two guards: the `permission_callback` returns false off
 * multisite (no one holds `manage_network_options` there), and `execute()` begins
 * with an explicit `is_multisite()` guard before touching any `ms-*`/network
 * function, mirroring the "explicit guard at the top of execute() when the
 * wrapped core fn has no route to surface an error" idiom (`og-tools/delete-transient`).
 *
 * Security: this is a GENERIC network-option read gated ONLY on the super-admin
 * `manage_network_options` capability (the hard guard). There is deliberately no
 * read allow-list here — unlike `og-settings/get-option`'s per-site allow-list —
 * because the owner chose generic network-option reads/writes behind the
 * network-admin cap. A super admin can already read any network option via
 * wp-admin / WP-CLI, so this is not weaker than core. Because the value is
 * returned as-is, treat it as potentially sensitive (a network option may store
 * plugin secrets); the capability is the only thing standing between the value
 * and the caller. The rejected/missing option name is not a secret, so it may
 * appear (echoed back) in the result.
 *
 * Classification rationale:
 * - `readonly` is true: this only reads stored data; it mutates nothing.
 * - `destructive` is false and `idempotent` is true: a read has no side effects.
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for a single
 * network option, so there is nothing for a consumer to deep-link
 * (RegistryTest guard: a `readonly:true` ability must have no screen entry).
 *
 * @since 0.6.0
 */
final class GetNetworkOption implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-network/get-network-option';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Network Option', 'abilities-catalog' ),
			'description'         => __( 'Reads a single network (site) option by name and returns whether it exists plus its current value. Pass the option name as stored in wp_sitemeta (e.g. "registration"). exists is false (and value null) when the option is not set; a stored false or empty string reports exists true. Omit network_id for the current network. Requires a multisite install and the manage_network_options (super-admin) capability; a network option may hold sensitive data.', 'abilities-catalog' ),
			'category'            => 'og-core-network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'option' ),
				'properties'           => array(
					'option'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The network option name to read (the meta_key in wp_sitemeta), e.g. "site_name" or "registration".', 'abilities-catalog' ),
					),
					'network_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Read from one network (multi-network installs). Discover IDs with og-network/list-networks. Omit for the current network.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'network_id', 'option', 'value', 'exists' ),
				'properties'           => array(
					'network_id' => array(
						'type'        => 'integer',
						'description' => __( 'The network ID the option was read from (resolves to the current network when omitted).', 'abilities-catalog' ),
					),
					'option'     => array(
						'type'        => 'string',
						'description' => __( 'The option name that was read, echoed back.', 'abilities-catalog' ),
					),
					'value'      => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The stored option value, returned as-is, or null when the option does not exist. May be a scalar, array, or object; a value that is not cleanly JSON-serializable may not round-trip.', 'abilities-catalog' ),
					),
					'exists'     => array(
						'type'        => 'boolean',
						'description' => __( 'True if the option is set on the network (including a stored false/empty value); false means it is not set.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'network',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: a multisite super admin who can manage network options.
	 *
	 * `manage_network_options` is the hard server-side guard and the only
	 * authorization for this generic network-option read (there is no read
	 * allow-list). It is object-independent: a missing option is a benign no-op
	 * result, never a permission denial. On a single site no one holds this
	 * capability and `is_multisite()` is false, so the ability is correctly
	 * inert there.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True on multisite when the current user can manage network options.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	/**
	 * Executes the ability by reading the named network option.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The read result, or a 400 off multisite.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$input      = is_array( $input ) ? $input : array();
		$option     = (string) ( $input['option'] ?? '' );
		$network_id = isset( $input['network_id'] ) ? absint( $input['network_id'] ) : null;

		// A class-private random sentinel (not a bare false): core returns the
		// default when the option is unset, and a stored false/''/0 is valid, so
		// existence is detected by identity-comparing this sentinel.
		$sentinel = '__abilities_catalog_network_option_missing__' . wp_generate_uuid4();
		$value    = get_network_option( $network_id, $option, $sentinel );
		$exists   = ( $sentinel !== $value );

		$resolved_network_id = $network_id ? $network_id : get_current_network_id();

		return array(
			'network_id' => (int) $resolved_network_id,
			'option'     => $option,
			'value'      => $exists ? $value : null,
			'exists'     => $exists,
		);
	}
}
