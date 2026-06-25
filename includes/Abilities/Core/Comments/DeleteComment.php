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
 * T2 destructive write ability: `og-comments/delete-comment`.
 *
 * Wraps `DELETE /wp/v2/comments/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the comment (bypassing the trash). The `permission_callback`
 * is a coarse `is_user_logged_in()` gate; the wrapped REST route enforces the
 * object-level capability (`delete_item_permissions_check` → `check_edit_permission`:
 * `moderate_comments` OR `edit_comment`) and surfaces the specific
 * `rest_comment_invalid_id` 404 / `rest_cannot_delete` 403, instead of the Abilities
 * API collapsing a `permission_callback` `WP_Error` into a generic permission
 * failure (see backlog B4). This
 * ability never calls `wp_delete_comment()` directly; it surfaces the REST route's
 * `WP_Error` unchanged. The output flattens a non-sensitive subset of the deleted
 * comment's prior data (`previous`) so a caller can describe what was removed;
 * PII fields (author email/IP) are deliberately omitted.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/delete-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Comment', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a comment by ID, bypassing the trash. Requires the moderate_comments capability or edit permission on the comment. Discover comment IDs with og-comments/list-comments or og-comments/get-comment first. This cannot be undone.', 'abilities-catalog' ),
			'category'            => 'og-core-comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to permanently delete. Find it with og-comments/list-comments or og-comments/get-comment.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the comment was permanently deleted.', 'abilities-catalog' ),
					),
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The deleted comment ID.', 'abilities-catalog' ),
					),
					'post'        => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post the deleted comment was on.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the deleted comment\'s parent, or 0 for a top-level comment.', 'abilities-catalog' ),
					),
					'author_name' => array(
						'type'        => 'string',
						'description' => __( 'The display name of the deleted comment author.', 'abilities-catalog' ),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __( 'The rendered content of the deleted comment.', 'abilities-catalog' ),
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
				'screen'       => 'edit-comments.php',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be logged in. The object-level
	 * capability is enforced by the wrapped REST route (`rest_do_request` runs the
	 * route's own `permission_callback`), so a missing comment surfaces as a 404 and
	 * an unauthorized delete as a 403 rather than a generic permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete, not trash).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, and prior comment fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data     = rest_get_server()->response_to_data( $response, false );
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();

		return array(
			'deleted'     => (bool) ( $data['deleted'] ?? false ),
			'id'          => $id,
			'post'        => (int) ( $previous['post'] ?? 0 ),
			'parent'      => (int) ( $previous['parent'] ?? 0 ),
			'author_name' => (string) ( $previous['author_name'] ?? '' ),
			'content'     => (string) ( $previous['content']['rendered'] ?? '' ),
		);
	}
}
