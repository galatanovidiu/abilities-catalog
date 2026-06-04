<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\BooleanInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `settings/update-media`.
 *
 * Updates the Media Settings screen. The accepted fields mirror the matching
 * read ability {@see GetMediaSettings}: thumbnail, medium, and large image
 * dimensions, thumbnail cropping, and the year/month upload folder flag.
 *
 * None of these options are in the core REST settings registry, so every
 * allow-listed key is written directly with `update_option()` after the
 * capability check and per-type sanitization.
 *
 * @since 0.3.0
 */
final class UpdateMediaSettings implements Ability {

	/**
	 * Allow-listed integer options written via `update_option()`.
	 *
	 * @var string[]
	 */
	private const INT_OPTIONS = array(
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
	);

	/**
	 * Allow-listed boolean options written via `update_option()`.
	 *
	 * @var string[]
	 */
	private const BOOL_OPTIONS = array( 'thumbnail_crop', 'uploads_use_yearmonth_folders' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'settings/update-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Media Settings', 'abilities-catalog' ),
			'description'         => __( 'Updates Media Settings: thumbnail, medium, and large image dimensions, thumbnail cropping, and the year/month upload folder flag.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
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
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'thumbnail_size_w' ),
				'properties'           => array(
					'thumbnail_size_w'              => array(
						'type'        => 'integer',
						'description' => __( 'The resulting thumbnail width.', 'abilities-catalog' ),
					),
					'thumbnail_size_h'              => array(
						'type'        => 'integer',
						'description' => __( 'The resulting thumbnail height.', 'abilities-catalog' ),
					),
					'thumbnail_crop'                => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting thumbnail crop flag.', 'abilities-catalog' ),
					),
					'medium_size_w'                 => array(
						'type'        => 'integer',
						'description' => __( 'The resulting medium width.', 'abilities-catalog' ),
					),
					'medium_size_h'                 => array(
						'type'        => 'integer',
						'description' => __( 'The resulting medium height.', 'abilities-catalog' ),
					),
					'large_size_w'                  => array(
						'type'        => 'integer',
						'description' => __( 'The resulting large width.', 'abilities-catalog' ),
					),
					'large_size_h'                  => array(
						'type'        => 'integer',
						'description' => __( 'The resulting large height.', 'abilities-catalog' ),
					),
					'uploads_use_yearmonth_folders' => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting year/month folder flag.', 'abilities-catalog' ),
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
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'options-media.php',
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
	 * Executes the ability by writing the Media Settings via `update_option()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The resulting media settings, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		// Defense in depth: update_option() does not re-check the capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'webmcp_forbidden',
				__( 'You are not allowed to update media settings.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		foreach ( self::INT_OPTIONS as $option ) {
			if ( ! array_key_exists( $option, $input ) ) {
				continue;
			}

			update_option( $option, absint( $input[ $option ] ) );
		}

		foreach ( self::BOOL_OPTIONS as $option ) {
			if ( ! array_key_exists( $option, $input ) ) {
				continue;
			}

			update_option( $option, BooleanInput::sanitize( $input[ $option ] ) ? 1 : 0 );
		}

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
