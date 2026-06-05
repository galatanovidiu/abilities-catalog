<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `comments/untrash-comment`.
 *
 * Restores a trashed comment to the status it held before being trashed, the
 * recovery counterpart to `comments/trash-comment`. Calls core
 * `wp_untrash_comment()` directly, which reads the `_wp_trash_meta_status` meta
 * (saved when the comment was trashed) and writes it back via
 * `wp_set_comment_status()`, then clears the trash bookkeeping meta. The core
 * function does not itself rewrite `comment_author_IP`, so this avoids the
 * REST-status corruption that affects the dispatch path (see backlog B3).
 *
 * The `permission_callback` is a coarse `is_user_logged_in()` gate; the
 * object-level `edit_comment` capability is enforced inside `execute()` so the
 * caller receives the specific `rest_comment_invalid_id` 404 / `rest_cannot_edit`
 * 403 instead of a generic permission failure (the Abilities API swallows a
 * `WP_Error` returned from a `permission_callback`; see backlog B4).
 *
 * Only a comment currently in the trash can be untrashed. A comment in any other
 * state is rejected with a `rest_comment_wrong_state` 409 error and left untouched,
 * because core `wp_untrash_comment()` has no in-trash guard: on a non-trashed
 * comment it would force the (absent) meta status, defaulting to `hold` — a silent
 * wrong mutation. The 409 mirrors the wrong-state convention established for
 * `comments/approve-comment` and `comments/unspam-comment` (backlog B5).
 *
 * @since 0.2.0
 */
final class UntrashComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'comments/untrash-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Untrash Comment', 'abilities-catalog' ),
			'description'         => __( 'Restores a comment from the trash to the status it held before it was trashed (for example "approved" or "hold"), the reverse of comments/trash-comment. Only a comment currently in the trash can be untrashed; a comment in any other state is rejected with a 409 "rest_comment_wrong_state" error. Discover trashed comment IDs with comments/list-comments (status "trash") first. Requires the moderate_comments capability or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to restore from the trash. Find it with comments/list-comments filtered to the "trash" status.', 'abilities-catalog' ),
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
						'description' => __( 'The restored comment status (the status it held before being trashed, e.g. "approved" or "hold").', 'abilities-catalog' ),
					),
					'previous_status' => array(
						'type'        => 'string',
						'description' => __( 'The comment status before this call. Always "trash", since only a trashed comment can be untrashed.', 'abilities-catalog' ),
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
	 * `edit_comment` capability is enforced in {@see execute()} so a missing
	 * comment surfaces as a 404 and an unauthorized restore as a 403, rather than
	 * the Abilities API collapsing either into a generic permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by restoring the comment from the trash via core
	 * `wp_untrash_comment()`, which writes back the saved pre-trash status and clears
	 * the trash bookkeeping meta.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id, restored status, and prior ("trash") status, or a WP_Error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = (int) ( $input['id'] ?? 0 );
		$comment = $id > 0 ? get_comment( $id ) : null;
		if ( null === $comment ) {
			return new WP_Error(
				'rest_comment_invalid_id',
				__( 'Invalid comment ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Object-level capability, enforced here (not in permission_callback) so the
		// 403 reaches the caller. Mirrors core check_edit_permission
		// (moderate_comments OR edit_comment). Placed before the wrong-state check so
		// an unauthorized caller never gets a state hint.
		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this comment.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		// Wrong-state guard: only a trashed comment can be untrashed. Core
		// wp_untrash_comment() has no in-trash guard; on a non-trashed comment it
		// would force the absent _wp_trash_meta_status (defaulting to 'hold') — a
		// silent wrong mutation. Reject before mutating anything (backlog B5).
		$previous_status = (string) wp_get_comment_status( $id );
		if ( 'trash' !== $previous_status ) {
			return new WP_Error(
				'rest_comment_wrong_state',
				__( 'This comment is not in the trash, so it cannot be untrashed.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		if ( ! wp_untrash_comment( $id ) ) {
			return new WP_Error(
				'rest_comment_failed_edit',
				__( 'Restoring the comment from the trash failed.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'id'              => $id,
			'status'          => $this->restStatus( (string) wp_get_comment_status( $id ) ),
			'previous_status' => $previous_status,
		);
	}

	/**
	 * Maps a `wp_get_comment_status()` value to the REST status vocabulary.
	 *
	 * Core stores an unapproved comment as `unapproved`; the REST API and this
	 * ability's output contract report it as `hold`. All other statuses pass through.
	 *
	 * @param string $status The raw `wp_get_comment_status()` value.
	 * @return string The REST-vocabulary status string.
	 */
	private function restStatus( string $status ): string {
		return 'unapproved' === $status ? 'hold' : $status;
	}
}
