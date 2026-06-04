<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `media/update-media`.
 *
 * Wraps `POST /wp/v2/media/<id>` via `rest_do_request()` to update an existing
 * attachment's metadata fields (title, alt text, caption, description, parent
 * post). The file itself is never changed. The `permission_callback` mirrors the
 * controller's `update_item_permissions_check` exactly: object-level `edit_post`
 * on the target attachment. Write annotations (`readonly:false, destructive:false,
 * idempotent:false`) route the outer `/run` call as POST.
 *
 * @since 0.3.0
 */
final class UpdateMedia implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'media/update-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Media', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing media item\'s title, alt text, caption, description, or parent post by ID. Does not change the file.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The attachment (media item) ID to update.', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The media title.', 'abilities-catalog' ),
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => __( 'Alternative text for the media item.', 'abilities-catalog' ),
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => __( 'The media caption.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The media description.', 'abilities-catalog' ),
					),
					'post'        => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post to attach the media to.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The attachment ID.', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The resulting rendered media title.', 'abilities-catalog' ),
					),
					'alt_text'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting alternative text.', 'abilities-catalog' ),
					),
					'caption'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting rendered caption.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The resulting rendered description.', 'abilities-catalog' ),
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
	 * Permission check mirroring `update_item_permissions_check`.
	 *
	 * Requires object-level `edit_post` on the target attachment.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the media item.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated media fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $id );

		foreach ( array( 'title', 'caption', 'description', 'alt_text' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		if ( ! empty( $input['post'] ) ) {
			$request->set_param( 'post', absint( $input['post'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'          => (int) ( $data['id'] ?? $id ),
			'title'       => (string) ( $data['title']['rendered'] ?? '' ),
			'alt_text'    => (string) ( $data['alt_text'] ?? '' ),
			'caption'     => (string) ( $data['caption']['rendered'] ?? '' ),
			'description' => (string) ( $data['description']['rendered'] ?? '' ),
		);
	}
}
