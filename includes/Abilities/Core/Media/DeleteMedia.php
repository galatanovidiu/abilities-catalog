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
 * permanently deleting the attachment (media has no Trash). The
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
			'description'         => __( 'Permanently deletes a media library item by ID. Media has no Trash, so this cannot be undone.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
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
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the media item was permanently deleted.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted attachment ID.', 'abilities-catalog' ),
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
	 * Permission check: object-level `delete_post` on the target attachment.
	 *
	 * Mirrors the attachments REST controller `delete_item_permissions_check`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the media item.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'delete_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=true` so the attachment is permanently deleted. Any REST error
	 * is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/media/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => $id,
		);
	}
}
