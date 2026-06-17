<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `media/delete-media`.
 *
 * Wraps `DELETE /wp/v2/media/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the attachment. Forcing `force=true` bypasses Trash, so
 * the deletion cannot be undone. The
 * `permission_callback` mirrors the attachments controller
 * `delete_item_permissions_check`: object-level `delete_post`. This ability never
 * calls `wp_delete_attachment()` directly; it surfaces the REST route's `WP_Error`
 * unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteMedia implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'media/delete-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Media', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a media library item by ID. The deletion bypasses Trash (force), so it cannot be undone, and clears any featured-image references to the item.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The attachment (media item) ID to permanently delete.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'             => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the media item was permanently deleted.', 'abilities-catalog' ),
					),
					'id'                  => array(
						'type'        => 'integer',
						'description' => __( 'The deleted attachment ID.', 'abilities-catalog' ),
					),
					'previous_title'      => array(
						'type'        => 'string',
						'description' => __( 'The title of the deleted media item.', 'abilities-catalog' ),
					),
					'previous_source_url' => array(
						'type'        => 'string',
						'description' => __( 'The file URL of the deleted media item.', 'abilities-catalog' ),
					),
					'previous_mime_type'  => array(
						'type'        => 'string',
						'description' => __( 'The MIME type of the deleted media item.', 'abilities-catalog' ),
					),
					'previous_media_type' => array(
						'type'        => 'string',
						'description' => __( 'The media type (image or file) of the deleted media item.', 'abilities-catalog' ),
					),
					'previous_alt_text'   => array(
						'type'        => 'string',
						'description' => __( 'The alternative text of the deleted media item.', 'abilities-catalog' ),
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
				),
				'show_in_rest' => true,
				'screen'       => 'upload.php',
			),
		);
	}

	/**
	 * Permission check: coarse `delete_posts` capability; the route enforces the object.
	 *
	 * `delete_posts` is the floor every successful deleter holds, so requiring it here
	 * is never stricter than core. The object-level `delete_post` decision (owner vs
	 * `delete_others_posts`) is left to the wrapped `DELETE /wp/v2/media/<id>` route, so
	 * its specific errors (`rest_post_invalid_id` 404, `rest_cannot_delete` 403) reach
	 * the caller instead of being collapsed into one generic denial — the Abilities API
	 * swallows a non-`true` return and replaces it with a single permission error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can delete attachments at all.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'delete_posts' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=true` so the attachment is permanently deleted. Any REST error
	 * is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, and removed item
	 *                                       identity, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = (int) ( $input['id'] ?? 0 );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/media/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data     = rest_get_server()->response_to_data( $response, false );
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();
		$title    = is_array( $previous['title'] ?? null ) ? $previous['title'] : array();

		return array(
			'deleted'             => (bool) ( $data['deleted'] ?? false ),
			'id'                  => $id,
			'previous_title'      => (string) ( $title['rendered'] ?? '' ),
			'previous_source_url' => (string) ( $previous['source_url'] ?? '' ),
			'previous_mime_type'  => (string) ( $previous['mime_type'] ?? '' ),
			'previous_media_type' => (string) ( $previous['media_type'] ?? '' ),
			'previous_alt_text'   => (string) ( $previous['alt_text'] ?? '' ),
		);
	}
}
