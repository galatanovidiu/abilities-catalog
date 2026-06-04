<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `settings/get-privacy`.
 *
 * Returns the Privacy Settings screen value, read directly from options.
 * Net-new read: no REST route is dispatched. Guarded by the dedicated
 * `manage_privacy_options` capability.
 *
 * @since 0.1.0
 */
final class GetPrivacy implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/get-privacy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Privacy Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the Privacy Settings screen value: the page ID assigned as the privacy policy page.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'page_for_privacy_policy' ),
				'properties'           => array(
					'page_for_privacy_policy' => array(
						'type'        => 'integer',
						'description' => __( 'The page ID set as the privacy policy page; 0 if none.', 'abilities-catalog' ),
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
	 * Permission check: the current user may manage privacy options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage privacy options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_privacy_options' );
	}

	/**
	 * Executes the ability by reading the privacy policy page option directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The privacy settings fields.
	 */
	public function execute( $input = null ) {
		return array(
			'page_for_privacy_policy' => absint( get_option( 'wp_page_for_privacy_policy' ) ),
		);
	}
}
