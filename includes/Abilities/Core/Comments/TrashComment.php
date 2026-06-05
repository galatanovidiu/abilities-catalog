<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `comments/trash-comment`.
 *
 * Wraps `DELETE /wp/v2/comments/<id>` with `force=false` via `rest_do_request()`,
 * moving the comment to the trash (recoverable, not a permanent delete). The
 * `permission_callback` is a coarse `is_user_logged_in()` gate; the wrapped REST
 * route enforces the object-level capability (`delete_item_permissions_check` →
 * `check_edit_permission`: `moderate_comments` OR `edit_comment`) and surfaces the
 * specific `rest_comment_invalid_id` 404 / `rest_cannot_delete` 403, instead of the
 * Abilities API collapsing a `permission_callback` `WP_Error` into a generic
 * permission failure (see backlog B4).
 * When trashing is disabled or unsupported the REST route returns a 501
 * `WP_Error`, and re-trashing an already-trashed comment returns a 410 `WP_Error`;
 * this ability surfaces both unchanged and never calls `wp_trash_comment()`
 * directly.
 *
 * @since 0.2.0
 */
final class TrashComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'comments/trash-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Trash Comment', 'abilities-catalog' ),
			'description'         => __( 'Moves a comment to the trash (recoverable). Requires the moderate_comments capability or edit permission on the comment. Discover comment IDs with comments/list-comments or comments/get-comment first. Returns a 501 error if trashing is disabled or unsupported on the site, and a 410 already-trashed error if the comment is already in the trash. Trashing a top-level note also trashes its child notes; standard comments do not cascade. Reversible via comments/untrash-comment.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to trash. Find it with comments/list-comments or comments/get-comment.', 'abilities-catalog' ),
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
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'status'          => array(
						'type'        => 'string',
						'description' => __( 'The resulting comment status (typically "trash").', 'abilities-catalog' ),
					),
					'previous_status' => array(
						'type'        => 'string',
						'description' => __( 'The comment status before it was trashed. This is the status comments/untrash-comment restores to.', 'abilities-catalog' ),
					),
					'post'            => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post the comment is on.', 'abilities-catalog' ),
					),
					'parent'          => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the comment\'s parent, or 0 for a top-level comment.', 'abilities-catalog' ),
					),
					'author_name'     => array(
						'type'        => 'string',
						'description' => __( 'The display name of the comment author.', 'abilities-catalog' ),
					),
					'type'            => array(
						'type'        => 'string',
						'description' => __( 'The comment type (for example "comment" or "note").', 'abilities-catalog' ),
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
				'screen'       => 'edit-comments.php?comment_status=trash',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be logged in. The object-level
	 * capability is enforced by the wrapped REST route (`rest_do_request` runs the
	 * route's own `permission_callback`), so a missing comment surfaces as a 404 and
	 * an unauthorized trash as a 403 rather than a generic permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=false` (trash, not permanent delete).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id, status, prior status, and identifying fields, or the REST error.
	 */
	public function execute( $input ) {
		$input           = is_array( $input ) ? $input : array();
		$id              = absint( $input['id'] ?? 0 );
		$previous_status = (string) wp_get_comment_status( $id );
		$request         = new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $id );
		$request->set_param( 'force', false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'              => (int) ( $data['id'] ?? $id ),
			'status'          => (string) ( $data['status'] ?? '' ),
			'previous_status' => $previous_status,
			'post'            => (int) ( $data['post'] ?? 0 ),
			'parent'          => (int) ( $data['parent'] ?? 0 ),
			'author_name'     => (string) ( $data['author_name'] ?? '' ),
			'type'            => (string) ( $data['type'] ?? '' ),
		);
	}
}
