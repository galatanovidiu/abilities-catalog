<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `media/edit-media-image`.
 *
 * Wraps `POST /wp/v2/media/<id>/edit` via `rest_do_request()` to apply image
 * transforms (rotation, crop, or a `modifiers` array) to an existing image
 * attachment. The route creates a NEW attachment record and leaves the original
 * untouched, so this is non-destructive. The route accepts image MIME types only
 * and validates the supplied `src` against the attachment's image metadata.
 *
 * The `permission_callback` mirrors the controller's
 * `edit_media_item_permissions_check` exactly: `upload_files` AND object-level
 * `edit_post` on the target attachment. Write annotations (`readonly:false,
 * destructive:false, idempotent:false`) route the outer `/run` call as POST.
 *
 * @since 0.3.0
 */
final class EditMediaImage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'media/edit-media-image';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Edit Media Image', 'abilities-catalog' ),
			'description'         => __( 'Applies rotation and/or crop transforms to an existing image attachment, creating a new edited attachment. The original image is preserved.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The image attachment ID to edit.', 'abilities-catalog' ),
					),
					'src'       => array(
						'type'        => 'string',
						'description' => __( 'URL to the image file being edited. Must match the attachment\'s image source.', 'abilities-catalog' ),
					),
					'rotation'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 359,
						'description' => __( 'Amount to rotate the image clockwise, in degrees (1-359).', 'abilities-catalog' ),
					),
					'x'         => array(
						'type'        => 'number',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => __( 'Crop start X position, as a percentage of the image width.', 'abilities-catalog' ),
					),
					'y'         => array(
						'type'        => 'number',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => __( 'Crop start Y position, as a percentage of the image height.', 'abilities-catalog' ),
					),
					'width'     => array(
						'type'        => 'number',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => __( 'Crop width, as a percentage of the image width.', 'abilities-catalog' ),
					),
					'height'    => array(
						'type'        => 'number',
						'minimum'     => 0,
						'maximum'     => 100,
						'description' => __( 'Crop height, as a percentage of the image height.', 'abilities-catalog' ),
					),
					'modifiers' => array(
						'type'        => 'array',
						'minItems'    => 1,
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'description' => __( 'Array of image edits ({type, args}). Takes precedence over rotation/crop fields.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id', 'src' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'source_url' ),
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => __( 'The new edited attachment ID.', 'abilities-catalog' ),
					),
					'source_url' => array(
						'type'        => 'string',
						'description' => __( 'The direct URL of the edited image file.', 'abilities-catalog' ),
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
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check mirroring `edit_media_item_permissions_check`.
	 *
	 * Requires `upload_files` AND object-level `edit_post` on the target attachment.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit the image.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST edit request.
	 *
	 * Passes through the provided transform params. The route returns a 400/404
	 * error (e.g. `rest_cannot_edit_file_type`, `rest_image_not_edited`,
	 * `rest_unknown_attachment`) when the input is invalid; it is surfaced unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new attachment's id and source URL, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $id . '/edit' );

		$request->set_param( 'src', (string) ( $input['src'] ?? '' ) );

		if ( isset( $input['rotation'] ) ) {
			$request->set_param( 'rotation', absint( $input['rotation'] ) );
		}

		foreach ( array( 'x', 'y', 'width', 'height' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			$request->set_param( $field, (float) $input[ $field ] );
		}

		if ( ! empty( $input['modifiers'] ) && is_array( $input['modifiers'] ) ) {
			$request->set_param( 'modifiers', $input['modifiers'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'         => (int) ( $data['id'] ?? 0 ),
			'source_url' => (string) ( $data['source_url'] ?? '' ),
		);
	}
}
