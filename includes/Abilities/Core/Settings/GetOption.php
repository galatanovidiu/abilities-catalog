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
 * T1 read ability: `settings/get-option`.
 *
 * The deny-by-default generic option reader. It reads a single WordPress option
 * by name, but only when that name is on the read allow-list in
 * {@see ReadableOptionAllowList::ALLOWED}. The allow-list is the single source of
 * truth: any name not on it is refused, with no separate denylist.
 *
 * This ability never reads secret-bearing options. Names such as `mailserver_pass`,
 * `mailserver_login`, and anything matching `*_pass`, `*_secret`, `*_key`, or
 * `*_token` are not on the allow-list and are therefore rejected. The stored value
 * is returned as a string via `get_option`. The `manage_options` capability is the
 * hard guard in all cases.
 *
 * @since 0.4.0
 */
final class GetOption implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/get-option';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Option (allow-listed)', 'abilities-catalog' ),
			'description'         => __( 'Reads a single WordPress option by name, but only when the name is on the read allow-list. Any other name is refused. Secret-bearing options are never readable through this tool.', 'abilities-catalog' ),
			'category'            => 'settings',
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
						'description' => __( 'The stored option value as a string.', 'abilities-catalog' ),
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
	 * the rejected name. Otherwise reads the stored value via `get_option` and returns
	 * it as a string.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The stored option, or a WP_Error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$name  = (string) ( $input['name'] ?? '' );

		if ( ! ReadableOptionAllowList::isAllowed( $name ) ) {
			return new WP_Error(
				'webmcp_option_not_readable',
				__( 'That option name is not on the read allow-list and cannot be read.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'name'  => $name,
			'value' => (string) get_option( $name ),
		);
	}
}
