<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Tools;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `tools/get-transient`.
 *
 * Reads a single transient's current value by name so an agent can inspect cached
 * state (for example the update-check transient). Wraps core `get_transient()` for a
 * normal transient, or `get_site_transient()` when `network` is true (a site/network
 * transient on multisite). The `key` is the transient name WITHOUT the internal
 * `_transient_` / `_site_transient_` storage prefix that core adds.
 *
 * Found semantics: both core functions return the stored value, or `false` when the
 * transient is unset, expired, or literally stores `false`. There is no separate
 * existence check in core, so `found` is derived as `( false !== $value )`. The
 * literal-`false` case is therefore reported as `found:false` with `value:null` — a
 * documented, unavoidable ambiguity.
 *
 * Value typing: a transient value is arbitrary (scalar, array, or a serializable
 * structure), so the `value` field is a JSON type union and is returned as-is. A
 * value that is not cleanly JSON-serializable (a PHP object graph) may not round-trip
 * through the tool surface.
 *
 * Classification rationale:
 * - `readonly` is true: this only reads stored data; it mutates nothing.
 * - `destructive` is false and `idempotent` is true: a read has no side effects.
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for a single
 * transient, so there is nothing for a consumer to deep-link.
 *
 * @since 0.5.0
 */
final class GetTransient implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'tools/get-transient';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Transient', 'abilities-catalog' ),
			'description'         => __( 'Reads a single transient (a cached value) by name and returns whether it was found plus its current value. Pass the transient name without the internal "_transient_"/"_site_transient_" prefix. Set network to true to read a site/network transient instead. A found of false means the transient is unset, expired, or (rarely) literally stored as false, and value is null in all three cases. A transient may cache plugin-stored data, so treat returned values as potentially sensitive.', 'abilities-catalog' ),
			'category'            => 'tools',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'key' ),
				'properties'           => array(
					'key'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The transient name, without the internal "_transient_"/"_site_transient_" prefix that WordPress adds when storing it. For example, pass "update_plugins" not "_site_transient_update_plugins".', 'abilities-catalog' ),
					),
					'network' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Whether to read a site/network transient (get_site_transient) instead of a normal transient (get_transient). Defaults to false. On a single site both stores behave the same; on multisite a site transient is shared across the network, so reading one requires network-admin (manage_network_options) capability.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'found', 'key', 'network', 'value' ),
				'properties'           => array(
					'found'   => array(
						'type'        => 'boolean',
						'description' => __( 'True if a transient value was read. False means it was unset, expired, or (rarely) literally stored as false — in all three cases value is null.', 'abilities-catalog' ),
					),
					'key'     => array(
						'type'        => 'string',
						'description' => __( 'The transient name that was targeted, echoed back (without the internal storage prefix).', 'abilities-catalog' ),
					),
					'network' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether a site/network transient was read (true) or a normal transient (false).', 'abilities-catalog' ),
					),
					'value'   => array(
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
						'description' => __( 'The stored transient value, returned as-is, or null when found is false. The value may be a scalar, array, or object; a value that is not cleanly JSON-serializable may not round-trip.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage options.
	 *
	 * Transients are site infrastructure, so `manage_options` is the baseline guard.
	 * Core's `get_transient()` / `get_site_transient()` check nothing, but this is a
	 * read with no mutation, so the Abilities API enforcing this callback before
	 * `execute()` is the only authorization needed — no execute()-top recheck (unlike
	 * the transient writes). The check is object-independent: there is no per-transient
	 * capability in core, so nothing is deferred to a wrapped route.
	 *
	 * A site/network transient (`network` true) is shared across the whole network on
	 * multisite, so reading it requires the network-admin capability
	 * `manage_network_options` there — a per-site `manage_options` admin must not read
	 * cross-site network state. On a single site both checks resolve to `manage_options`.
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
	 * Executes the ability by reading the named transient.
	 *
	 * Both core functions return the stored value or `false` for unset/expired/literal
	 * false, so `found` is derived as `( false !== $value )` and `value` is forced to
	 * null when not found.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The read result.
	 */
	public function execute( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$key     = isset( $input['key'] ) ? (string) $input['key'] : '';
		$network = ! empty( $input['network'] );

		$value = $network
			? get_site_transient( $key )
			: get_transient( $key );

		$found = ( false !== $value );

		return array(
			'found'   => $found,
			'key'     => $key,
			'network' => $network,
			'value'   => $found ? $value : null,
		);
	}
}
