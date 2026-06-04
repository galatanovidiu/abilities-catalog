<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `content/trash-post`.
 *
 * Wraps `DELETE /wp/v2/posts/<id>` with `force=false` via `rest_do_request()`,
 * moving the post to Trash (recoverable). The `permission_callback` encodes the
 * catalog's object-level `delete_post` capability. When Trash is disabled
 * (`EMPTY_TRASH_DAYS` is 0) the REST route returns a 501 `rest_trash_not_supported`
 * error, which is surfaced unchanged; this ability never calls `wp_trash_post()`
 * directly.
 *
 * @since 0.2.0
 */
final class TrashPost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/trash-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Trash Post', 'abilities-catalog' ),
			'description'         => __( 'Moves a post to the Trash by ID. The post is recoverable. Fails if Trash is disabled on the site.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to move to Trash.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'The resulting post status (trash).', 'abilities-catalog' ),
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
				'screen'       => 'edit.php',
			),
		);
	}

	/**
	 * Permission check: type-level `delete_posts` as the coarse guard.
	 *
	 * Object-independent so a missing or non-existent id is not masked as a
	 * permission failure. The object-level `delete_post` check and the specific
	 * `rest_post_invalid_id` (404) / `rest_cannot_delete` (403) errors come from
	 * the wrapped `DELETE /wp/v2/posts/<id>` route in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may trash posts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'delete_posts' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=false` so the post is trashed (not permanently deleted). A
	 * 501 `rest_trash_not_supported` error from the route (Trash disabled) is
	 * returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post's id and status, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $id );
		$request->set_param( 'force', false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'     => (int) ( $data['id'] ?? $id ),
			'status' => (string) ( $data['status'] ?? 'trash' ),
		);
	}
}
