<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `media/list-image-sizes`.
 *
 * Lists the image sub-sizes WordPress generates for uploads — the core sizes
 * (thumbnail, medium, medium_large, large) plus any registered by the theme or
 * plugins — each with its target width, height, and crop behavior. Use it to know
 * which size names `media/regenerate-thumbnails` can produce, or to understand the
 * derivatives available for an image. Wraps core
 * `wp_get_registered_image_subsizes()`. This reports the configured sizes, not the
 * files that exist for any one attachment.
 *
 * @since 0.5.0
 */
final class ListImageSizes implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'media/list-image-sizes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Image Sizes', 'abilities-catalog' ),
			'description'         => __( 'Returns the image sub-sizes WordPress generates for uploads (core sizes plus any registered by the theme or plugins), each with its width, height, and crop setting. Reports the configured sizes, not the files present for a specific attachment.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sizes' ),
				'properties'           => array(
					'sizes' => array(
						'type'        => 'array',
						'description' => __( 'The registered image sub-sizes.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'name', 'width', 'height', 'crop' ),
							'properties'           => array(
								'name'   => array(
									'type'        => 'string',
									'description' => __( 'The size name (e.g. "thumbnail", "medium", "large").', 'abilities-catalog' ),
								),
								'width'  => array(
									'type'        => 'integer',
									'description' => __( 'The maximum width in pixels.', 'abilities-catalog' ),
								),
								'height' => array(
									'type'        => 'integer',
									'description' => __( 'The maximum height in pixels.', 'abilities-catalog' ),
								),
								'crop'   => array(
									'type'        => 'boolean',
									'description' => __( 'True if the size is hard-cropped to the exact dimensions; false if scaled to fit.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
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
	 * Permission check: reading image-size configuration requires upload access.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return bool True if the current user may list image sizes.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Executes the ability by reading the registered image sub-sizes.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return array<string,mixed> The registered image sizes.
	 */
	public function execute( $input = null ): array {
		$sizes = array();
		foreach ( wp_get_registered_image_subsizes() as $name => $data ) {
			$sizes[] = array(
				'name'   => (string) $name,
				'width'  => (int) ( $data['width'] ?? 0 ),
				'height' => (int) ( $data['height'] ?? 0 ),
				'crop'   => (bool) ( $data['crop'] ?? false ),
			);
		}

		return array(
			'sizes' => $sizes,
		);
	}
}
