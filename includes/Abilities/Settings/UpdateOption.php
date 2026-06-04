<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\OptionAllowList;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous-tier write ability: `settings/update-option`.
 *
 * The deny-by-default generic option writer. It writes a single WordPress option
 * by name, but only when that name is on the allow-list in
 * {@see OptionAllowList::ALLOWED}. The allow-list is the single source of truth:
 * any name not on it is refused, with no separate denylist.
 *
 * This ability never writes site-defining, security-sensitive, or serialized
 * options. Names such as `siteurl`, `home`, `active_plugins`, `template`,
 * `stylesheet`, `user_roles`, `db_version`, `default_role`, `users_can_register`,
 * and `cron` are not on the allow-list and are therefore rejected.
 *
 * Because it can change site behaviour broadly, it is marked `dangerous` and is
 * exposed to the browser only under the adapter's dangerous-tier gate. The
 * `manage_options` capability is the hard guard in all cases.
 *
 * Each allowed option keeps its core-registered sanitize callback: `update_option`
 * runs `sanitize_option($name, $value)` internally, so the stored value is still
 * cleaned by core. The result echoes back the stored value via `get_option`.
 *
 * @since 0.5.0
 */
final class UpdateOption implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/update-option';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Option (allow-listed)', 'abilities-catalog' ),
			'description'         => __( 'Writes a single WordPress option by name, but only when the name is on the allow-list. Any other name is refused. Site-defining, security, and serialized options are never writable through this tool.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'value' ),
				'properties'           => array(
					'name'  => array(
						'type'        => 'string',
						'description' => __( 'The option name to write. Must be one of the allow-listed option names; any other name is refused.', 'abilities-catalog' ),
						'enum'        => OptionAllowList::ALLOWED,
					),
					'value' => array(
						'type'        => 'string',
						'description' => __( 'The new value as a string. Numeric and boolean options are coerced by the option\'s registered sanitizer when stored.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'name', 'value', 'updated' ),
				'properties'           => array(
					'name'    => array(
						'type'        => 'string',
						'description' => __( 'The option name that was written.', 'abilities-catalog' ),
					),
					'value'   => array(
						'type'        => 'string',
						'description' => __( 'The stored value after sanitization, read back via get_option.', 'abilities-catalog' ),
					),
					'updated' => array(
						'type'        => 'boolean',
						'description' => __( 'True once the option has been written.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: required input is present, the option name is allow-listed,
	 * and the current user may manage options.
	 *
	 * The allow-list check here is defense in depth; {@see self::execute()} repeats
	 * it as the authoritative guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the call may proceed.
	 */
	public function hasPermission( $input = null ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! isset( $input['name'] ) || ! isset( $input['value'] ) ) {
			return false;
		}

		if ( ! OptionAllowList::isAllowed( (string) $input['name'] ) ) {
			return false;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by writing the allow-listed option.
	 *
	 * Refuses any name not on {@see OptionAllowList::ALLOWED} without echoing the
	 * rejected name or value. Otherwise writes via `update_option` (which runs the
	 * option's registered sanitizer) and reads the stored value back. A `false`
	 * return from `update_option` means the value was unchanged, which is not an
	 * error, so the stored value is re-read either way.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The stored option, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$name  = isset( $input['name'] ) ? (string) $input['name'] : '';
		$value = isset( $input['value'] ) ? (string) $input['value'] : '';

		if ( ! OptionAllowList::isAllowed( $name ) ) {
			return new WP_Error(
				'webmcp_option_not_allowed',
				__( 'That option name is not on the allow-list and cannot be written.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		update_option( $name, $value );

		return array(
			'name'    => $name,
			'value'   => (string) get_option( $name ),
			'updated' => true,
		);
	}
}
