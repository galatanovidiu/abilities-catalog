<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ReadableOptionAllowList;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-settings/get-option`.
 *
 * The deny-by-default generic option reader. It reads a single WordPress option
 * by name, but only when that name is on the read allow-list in
 * {@see ReadableOptionAllowList::ALLOWED}. The allow-list is the single source of
 * truth: any name not on it is refused, with no separate denylist.
 *
 * This ability never reads secret-bearing options. Names such as `mailserver_pass`,
 * `mailserver_login`, and anything matching `*_pass`, `*_secret`, `*_key`, or
 * `*_token` are not on the allow-list and are therefore rejected. The effective
 * `get_option` value is returned as a string. This is the value core resolves, with
 * `option_{$name}` filters and core normalization applied (for example the
 * `home`->`siteurl` fallback and `untrailingslashit`), not a raw stored read. The
 * `manage_options` capability is the hard guard in all cases.
 *
 * @since 0.4.0
 */
final class GetOption implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/get-option';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Option (allow-listed)', 'abilities-catalog' ),
			'description'         => __( 'Reads a single WordPress option by name, but only when the name is on the read allow-list. Any other name is refused. Secret-bearing options are never readable through this tool.', 'abilities-catalog' ),
			'category'            => 'og-core-settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name' ),
				'properties'           => array(
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The option name to read. Must be one of the read allow-listed option names; any other name is refused.', 'abilities-catalog' ),
						'enum'        => ReadableOptionAllowList::ALLOWED,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'value' ),
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => __( 'The option name that was read.', 'abilities-catalog' ),
					),
					'value' => array(
						'type'        => 'string',
						'description' => __( 'The effective option value as a string, as resolved by get_option (filters and core normalization applied).', 'abilities-catalog' ),
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
	 * Permission check: the option name is present and allow-listed, and the current
	 * user may manage options.
	 *
	 * The allow-list check here is defense in depth; {@see self::execute()} repeats
	 * it as the authoritative guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the call may proceed.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! isset( $input['name'] ) ) {
			return false;
		}

		if ( ! ReadableOptionAllowList::isAllowed( (string) $input['name'] ) ) {
			return false;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by reading the allow-listed option.
	 *
	 * Refuses any name not on {@see ReadableOptionAllowList::ALLOWED} without echoing
	 * the rejected name. Otherwise reads the effective value via `get_option` and
	 * returns it as a string.
	 *
	 * Every allow-listed name is a scalar option in core, but an `option_{$name}`
	 * filter can make `get_option` return a non-scalar value. Casting such a value to
	 * string would emit a PHP warning and yield `"Array"`, so a non-scalar result
	 * (other than `null`/`false`) is refused with a stable error instead.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The effective option value, or a WP_Error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$name  = (string) ( $input['name'] ?? '' );

		if ( ! ReadableOptionAllowList::isAllowed( $name ) ) {
			return new WP_Error(
				'abilities_catalog_option_not_readable',
				__( 'That option name is not on the read allow-list and cannot be read.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$value = get_option( $name );

		if ( null !== $value && false !== $value && ! is_scalar( $value ) ) {
			return new WP_Error(
				'abilities_catalog_option_not_scalar',
				__( 'That option does not resolve to a scalar value and cannot be returned as a string.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'name'  => $name,
			'value' => (string) $value,
		);
	}
}
