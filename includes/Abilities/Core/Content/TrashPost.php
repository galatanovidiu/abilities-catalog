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
 * moving the post to Trash (recoverable). The `permission_callback` enforces the
 * type-level `delete_posts` capability as a coarse guard; object-level
 * `delete_post` is enforced by the wrapped route. When Trash is disabled or
 * unsupported (`EMPTY_TRASH_DAYS` is 0, or the `rest_post_trashable` filter
 * returns false) the REST route returns a 501 `rest_trash_not_supported` error;
 * trashing an already-trashed post returns a 410 `rest_already_trashed` error.
 * Both are surfaced unchanged; this ability never calls `wp_trash_post()`
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
			'description'         => __( 'Moves a post to the Trash by ID. The post is recoverable. Fails if Trash is disabled or unsupported on the site, or if the post is already in the Trash.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
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
					'id'              => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'title'           => array(
						'type'        => 'string',
						'description' => __( 'The rendered title of the trashed post, so a human can confirm what was moved to Trash.', 'abilities-catalog' ),
					),
					'status'          => array(
						'type'        => 'string',
						'description' => __( 'The resulting post status (trash). The post is recoverable from Posts → Trash. No edit_link is returned: a trashed post cannot be opened in the editor (wp-admin returns HTTP 409); it must be restored first.', 'abilities-catalog' ),
					),
					'previous_status' => array(
						'type'        => 'string',
						'description' => __( 'The post status before trashing (e.g. publish, draft). Core records this so a later restore re-applies it; use it to tell whether a live post was taken offline or only a draft was trashed.', 'abilities-catalog' ),
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
	 * 501 `rest_trash_not_supported` error (Trash disabled or unsupported) or a
	 * 410 `rest_already_trashed` error (post already in Trash) from the route is
	 * returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post's id, title, status, and previous_status, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );

		// Capture the pre-trash status before dispatch. Core records it in
		// _wp_trash_meta_status and re-applies it on restore; returning it lets
		// the caller tell whether a live post was taken offline or a draft was
		// trashed, and what a later restore will re-expose.
		$previous_status = (string) get_post_status( $id );

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $id );
		$request->set_param( 'force', false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$post_id = (int) ( $data['id'] ?? $id );

		// No edit_link: a trashed post cannot be edited. wp-admin/post.php wp_die()s
		// with HTTP 409 ("you cannot edit this item because it is in the Trash") for
		// any post whose status is 'trash', so get_edit_post_link() would hand back a
		// URL that dead-ends. The post must be restored before it can be edited.
		return array(
			'id'              => $post_id,
			'title'           => (string) ( $data['title']['rendered'] ?? '' ),
			'status'          => (string) ( $data['status'] ?? 'trash' ),
			'previous_status' => $previous_status,
		);
	}
}
