<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 write ability: `og-media/regenerate-thumbnails`.
 *
 * Rebuilds the resized image files (thumbnails and other sub-sizes) for one image
 * attachment from its current attached image file, then refreshes the attachment metadata. Use
 * it after changing registered image sizes or when an image's derivatives are
 * missing. It rewrites only the generated derivatives; the original upload is not
 * changed, so this is non-destructive. Wraps core
 * `wp_generate_attachment_metadata()` and `wp_update_attachment_metadata()`.
 * Returns the attachment `id`, the regenerated `sizes`, and `edit_link` (the
 * wp-admin URL); surface `edit_link` so a human can review the media item.
 *
 * @since 0.5.0
 */
final class RegenerateThumbnails implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/regenerate-thumbnails';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Regenerate Thumbnails', 'abilities-catalog' ),
			'description'         => __( 'Rebuilds the resized image files (thumbnails and other sub-sizes) for an image attachment from its current attached image file and refreshes its metadata. Only the generated derivatives are rewritten; the original upload is preserved. Returns the attachment id, the regenerated sizes, and edit_link — surface edit_link so a human can review the media item.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The image attachment ID to regenerate sizes for.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'sizes', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID.', 'abilities-catalog' ),
					),
					'sizes'     => array(
						'type'        => 'array',
						'description' => __( 'The image sub-sizes that were regenerated.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'name', 'width', 'height' ),
							'properties'           => array(
								'name'   => array(
									'type'        => 'string',
									'description' => __( 'The size name.', 'abilities-catalog' ),
								),
								'width'  => array(
									'type'        => 'integer',
									'description' => __( 'The generated width in pixels.', 'abilities-catalog' ),
								),
								'height' => array(
									'type'        => 'integer',
									'description' => __( 'The generated height in pixels.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the media item. Surface this so a human can review it.', 'abilities-catalog' ),
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
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: coarse `upload_files`; the object guard is in execute().
	 *
	 * `upload_files` is the object-independent floor the Media Library requires. This
	 * ability calls `wp_generate_attachment_metadata()` directly (no wrapped route), so
	 * the object-level `edit_post` check is enforced in {@see self::execute()} where its
	 * specific 404/403 reaches the caller — doing it here would collapse the
	 * non-existent-id 404 and the not-your-attachment 403 into one opaque
	 * `ability_invalid_permissions` (the Abilities API swallows a non-`true` return).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can use the media library at all.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Executes the ability by regenerating attachment metadata for the image.
	 *
	 * `wp_generate_attachment_metadata()` lives in an admin include not loaded
	 * during REST requests, so it is required first via {@see AdminIncludes::load()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The attachment id, regenerated sizes, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$post  = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid attachment ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		// Object-level guard (relocated from permission_callback): only a user who can
		// edit this attachment may rewrite its derivatives — checked before any work.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to edit this attachment.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return new WP_Error( 'rest_not_an_image', __( 'The attachment is not an image; only images have resizable sub-sizes.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'rest_file_missing', __( 'The original image file could not be found on disk.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		AdminIncludes::load( 'image', 'media' );

		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		if ( empty( $metadata ) ) {
			return new WP_Error( 'rest_regenerate_failed', __( 'Could not regenerate image sizes. The image editor may be unavailable.', 'abilities-catalog' ), array( 'status' => 500 ) );
		}

		wp_update_attachment_metadata( $id, $metadata );

		$sizes = array();
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $data ) {
				$sizes[] = array(
					'name'   => (string) $name,
					'width'  => (int) ( $data['width'] ?? 0 ),
					'height' => (int) ( $data['height'] ?? 0 ),
				);
			}
		}

		return array(
			'id'        => $id,
			'sizes'     => $sizes,
			'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}
}
