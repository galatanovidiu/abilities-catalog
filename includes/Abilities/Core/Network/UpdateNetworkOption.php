<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `network/update-network-option`.
 *
 * Writes a single network (site) option by name to an arbitrary value for the
 * whole multisite network. Wraps core `update_network_option()`
 * (wp-includes/option.php:2383), which returns a bool; a null/0 `$network_id`
 * resolves to the current network (option.php:2393-2395). No REST route exposes
 * network options and the function calls no wp-admin-only code, so this uses the
 * core-function idiom with no `AdminIncludes::load`.
 *
 * Value typing: a network option value is arbitrary (a scalar, array, or
 * serializable structure), so the `value` field is a JSON-type union and is
 * stored as-is. A value that is not cleanly JSON/PHP-serializable (e.g. a live
 * object graph or a resource) may not round-trip.
 *
 * Success signal — read back, do not trust the raw bool: core's
 * `update_network_option()` returns `false` when the new value EQUALS the value
 * already stored (option.php:2427-2429, the same no-op-false semantics as
 * `set_transient`/`update_option`). A raw `false` therefore does NOT reliably
 * mean failure (re-writing the same value returns a real `false` that is
 * actually fine). So {@see self::execute()} reads the option back after writing
 * and derives `updated` from a sentinel-defaulted read compared with the
 * requested value (`maybe_serialize()` equality, matching core's own check at
 * :2427). This makes re-writing an unchanged value correctly report
 * `updated: true`.
 *
 * Multisite-only: network options live in `wp_sitemeta`, which only exists on a
 * multisite install. Two guards: the `permission_callback` returns false off
 * multisite (no one holds `manage_network_options` there), and `execute()` begins
 * with an explicit `is_multisite()` guard before touching any network function.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it stores network configuration).
 * - `destructive` is false: overwriting an option is not, on its own,
 *   irreversible data loss — the caller can write the prior value back (read it
 *   first with `network/get-network-option`).
 * - `idempotent` is true: writing the same value twice leaves the same end state.
 * - `dangerous` is true: this is a raw network-wide config write with NO write
 *   allow-list, and a bad value for a core network option can break the whole
 *   network. The Registry auto-lists any `dangerous` ability in the
 *   `abilities_catalog_dangerous_tools` filter. There is no `Support/` guard (no
 *   filesystem/source/upgrader/option-allow-list risk class applies); the hard
 *   guard is the `manage_network_options` super-admin capability plus the
 *   in-`execute()` cap repeat below.
 *
 * No `meta.screen` is set: there is no single wp-admin screen for an arbitrary
 * network option, so there is nothing for a consumer to deep-link (mirrors the
 * generic per-site option pair, which also omits `screen`).
 *
 * Security: this is a GENERIC network-option write gated ONLY on the super-admin
 * `manage_network_options` capability (the hard guard). There is deliberately NO
 * write allow-list here — unlike `settings/update-option`'s `OptionAllowList` —
 * because the owner chose generic network-option writes behind the network-admin
 * cap. A super admin can already write any network option via wp-admin / WP-CLI,
 * so this is not weaker than core. `update_network_option()` performs no
 * capability check of its own, so the `permission_callback` plus the explicit
 * `current_user_can( 'manage_network_options' )` check at the top of
 * {@see self::execute()} are the only authorization guards. The stored value is
 * never echoed back in any error message.
 *
 * @since 0.6.0
 */
final class UpdateNetworkOption implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/update-network-option';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Network Option', 'abilities-catalog' ),
			'description'         => __( 'Writes a single network (site) option by name to a value, for the whole multisite network. Pass the option name as stored in wp_sitemeta (e.g. "registration") and any JSON value; it is stored as-is and confirmed by a read-back (updated is true even when re-writing an unchanged value). Omit network_id for the current network. This is a raw, allow-list-free network-wide config write — the manage_network_options super-admin capability is the only guard, and a bad value for a core option can break the network, so this is a dangerous operation. Read an option first with network/get-network-option. Requires a multisite install and the manage_network_options (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'option', 'value' ),
				'properties'           => array(
					'option'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The network option name to write (the meta_key in wp_sitemeta), e.g. "registration" or "site_name".', 'abilities-catalog' ),
					),
					'value'      => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The value to store, kept as-is. Any JSON type is accepted (string, number, boolean, object, array, or null). WordPress serializes it on save; a value that is not cleanly serializable may not round-trip.', 'abilities-catalog' ),
					),
					'network_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Optional. Write to one network (multi-network installs). Discover IDs with network/list-networks. Omit for the current network.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'updated', 'network_id', 'option' ),
				'properties'           => array(
					'updated'    => array(
						'type'        => 'boolean',
						'description' => __( 'True if the option now holds the requested value (confirmed by a read-back). Re-writing the same value still reports true.', 'abilities-catalog' ),
					),
					'network_id' => array(
						'type'        => 'integer',
						'description' => __( 'The network ID the option was written to (resolves to the current network when omitted).', 'abilities-catalog' ),
					),
					'option'     => array(
						'type'        => 'string',
						'description' => __( 'The option name that was written, echoed back.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: a multisite super admin who can manage network options.
	 *
	 * `manage_network_options` is the hard server-side guard and the only
	 * authorization for this generic network-option write (there is no write
	 * allow-list). It is object-independent. On a single site no one holds this
	 * capability and `is_multisite()` is false, so the ability is correctly inert
	 * there. The matching check in {@see self::execute()} is the defense-in-depth
	 * repeat because the wrapped core function checks no capability and there is no
	 * route to surface a denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True on multisite when the current user can manage network options.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network_options' );
	}

	/**
	 * Executes the ability by writing the named network option.
	 *
	 * The explicit `current_user_can( 'manage_network_options' )` check is repeated
	 * here, after the multisite guard and before the write, because the wrapped core
	 * function performs no capability check of its own. After writing, the option is
	 * read back with a class-private random sentinel default and `updated` is derived
	 * by comparing the stored value with the requested value (a `maybe_serialize()`
	 * equality, matching core's no-op short-circuit at option.php:2427), so an
	 * unchanged re-write correctly reports `updated: true`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The update result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'manage_network_options' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_network_options',
				__( 'You are not allowed to update network options.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input      = is_array( $input ) ? $input : array();
		$option     = (string) ( $input['option'] ?? '' );
		$value      = $input['value'] ?? null;
		$network_id = isset( $input['network_id'] ) ? absint( $input['network_id'] ) : null;

		if ( '' === $option ) {
			return new WP_Error(
				'abilities_catalog_invalid_option',
				__( 'A non-empty option name is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		update_network_option( $network_id, $option, $value );

		// Read-back, do not trust the raw bool: update_network_option() returns
		// false when the new value equals the stored value, so confirm the option
		// now holds the requested value. A class-private random sentinel default
		// distinguishes "not stored" from a stored falsy value, and the comparison
		// mirrors core's own maybe_serialize() equality test (option.php:2427).
		$sentinel = '__abilities_catalog_network_option_missing__' . wp_generate_uuid4();
		$stored   = get_network_option( $network_id, $option, $sentinel );
		$updated  = ( $sentinel !== $stored )
			&& ( $stored === $value || maybe_serialize( $stored ) === maybe_serialize( $value ) );

		$resolved_network_id = $network_id ? $network_id : get_current_network_id();

		return array(
			'updated'    => $updated,
			'network_id' => (int) $resolved_network_id,
			'option'     => $option,
		);
	}
}
