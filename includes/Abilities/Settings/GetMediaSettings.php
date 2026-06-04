<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `settings/get-media`.
 *
 * Returns the Media Settings screen values, read directly from options.
 * Net-new read: no REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetMediaSettings implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/get-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Media Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the Media Settings screen values: thumbnail, medium, and large image dimensions, thumbnail cropping, and the year/month upload folder flag.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'thumbnail_size_w' ),
				'properties'           => array(
					'thumbnail_size_w'              => array(
						'type'        => 'integer',
						'description' => __( 'Thumbnail image width in pixels.', 'abilities-catalog' ),
					),
					'thumbnail_size_h'              => array(
						'type'        => 'integer',
						'description' => __( 'Thumbnail image height in pixels.', 'abilities-catalog' ),
					),
					'thumbnail_crop'                => array(
						'type'        => 'boolean',
						'description' => __( 'Whether thumbnails are cropped to exact dimensions.', 'abilities-catalog' ),
					),
					'medium_size_w'                 => array(
						'type'        => 'integer',
						'description' => __( 'Medium image maximum width in pixels.', 'abilities-catalog' ),
					),
					'medium_size_h'                 => array(
						'type'        => 'integer',
						'description' => __( 'Medium image maximum height in pixels.', 'abilities-catalog' ),
					),
					'large_size_w'                  => array(
						'type'        => 'integer',
						'description' => __( 'Large image maximum width in pixels.', 'abilities-catalog' ),
					),
					'large_size_h'                  => array(
						'type'        => 'integer',
						'description' => __( 'Large image maximum height in pixels.', 'abilities-catalog' ),
					),
					'uploads_use_yearmonth_folders' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether uploads are organized into year- and month-based folders.', 'abilities-catalog' ),
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
	 * Executes the ability by reading media settings directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The media settings fields.
	 */
	public function execute( $input = null ) {
		return array(
			'thumbnail_size_w'              => absint( get_option( 'thumbnail_size_w' ) ),
			'thumbnail_size_h'              => absint( get_option( 'thumbnail_size_h' ) ),
			'thumbnail_crop'                => (bool) get_option( 'thumbnail_crop' ),
			'medium_size_w'                 => absint( get_option( 'medium_size_w' ) ),
			'medium_size_h'                 => absint( get_option( 'medium_size_h' ) ),
			'large_size_w'                  => absint( get_option( 'large_size_w' ) ),
			'large_size_h'                  => absint( get_option( 'large_size_h' ) ),
			'uploads_use_yearmonth_folders' => (bool) get_option( 'uploads_use_yearmonth_folders' ),
		);
	}
}
