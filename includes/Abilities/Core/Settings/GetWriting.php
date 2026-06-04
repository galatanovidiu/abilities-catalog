<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `settings/get-writing`.
 *
 * Returns the Writing Settings screen values, read directly from options.
 * Net-new read: no REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetWriting implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/get-writing';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Writing Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the Writing Settings screen values: default category, default post format, and the smilies conversion flag.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'default_category' ),
				'properties'           => array(
					'default_category'    => array(
						'type'        => 'integer',
						'description' => __( 'The default post category term ID.', 'abilities-catalog' ),
					),
					'default_post_format' => array(
						'type'        => 'string',
						'description' => __( 'The default post format (e.g. "standard").', 'abilities-catalog' ),
					),
					'use_smilies'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to convert text smileys to graphics on display.', 'abilities-catalog' ),
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
	 * Executes the ability by reading writing settings directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The writing settings fields.
	 */
	public function execute( $input = null ) {
		return array(
			'default_category'    => absint( get_option( 'default_category' ) ),
			'default_post_format' => (string) ( get_option( 'default_post_format' ) ?: 'standard' ),
			'use_smilies'         => (bool) get_option( 'use_smilies' ),
		);
	}
}
