<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `settings/get-permalinks`.
 *
 * Returns the Permalink Settings screen values, read directly from options.
 * Net-new read: no REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetPermalinks implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/get-permalinks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Permalink Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the Permalink Settings screen values: the permalink structure and the category and tag base prefixes.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'permalink_structure' ),
				'properties'           => array(
					'permalink_structure' => array(
						'type'        => 'string',
						'description' => __( 'The permalink structure tag string; empty means plain permalinks.', 'abilities-catalog' ),
					),
					'category_base'       => array(
						'type'        => 'string',
						'description' => __( 'The base prefix for category URLs; empty means the default.', 'abilities-catalog' ),
					),
					'tag_base'            => array(
						'type'        => 'string',
						'description' => __( 'The base prefix for tag URLs; empty means the default.', 'abilities-catalog' ),
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
	 * Permission check: the current user may manage options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by reading permalink settings directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The permalink settings fields.
	 */
	public function execute( $input = null ) {
		return array(
			'permalink_structure' => (string) ( get_option( 'permalink_structure' ) ?? '' ),
			'category_base'       => (string) ( get_option( 'category_base' ) ?? '' ),
			'tag_base'            => (string) ( get_option( 'tag_base' ) ?? '' ),
		);
	}
}
