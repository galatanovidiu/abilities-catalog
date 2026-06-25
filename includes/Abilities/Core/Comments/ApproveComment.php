<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-comments/approve-comment`.
 *
 * Net-new framing over a comment status change to `approve`. Calls core
 * `wp_set_comment_status()` directly rather than the REST update path: the REST
 * path always rewrites `comment_author_IP` from `REMOTE_ADDR` in
 * `prepare_item_for_database`, so in a non-browser run every status change
 * corrupts the stored commenter IP (see backlog B3). The `permission_callback`
 * is a coarse `is_user_logged_in()` gate; the object-level `edit_comment`
 * capability is enforced inside `execute()` so the caller receives the specific
 * `rest_comment_invalid_id` 404 / `rest_cannot_edit` 403 instead of a generic
 * permission failure (the Abilities API swallows a `WP_Error` returned from a
 * `permission_callback`; see backlog B4). Approves only a `hold`/`unapproved`
 * comment. A `spam` or `trash` comment is rejected with a `rest_comment_wrong_state`
 * 409 error (unspam or restore it first), because `wp_set_comment_status()` only
 * flips `comment_approved` and skips the unspam/untrash restore path, leaving the
 * `_wp_trash_meta_status` meta stale. Re-approving an already-approved comment is a
 * benign no-op that reports the existing `approved` status. By contrast,
 * `og-comments/unapprove-comment` accepts any state, so the asymmetry is deliberate.
 *
 * @since 0.2.0
 */
final class ApproveComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/approve-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Approve Comment', 'abilities-catalog' ),
			'description'         => __( 'Approves a "hold"/"unapproved" comment, setting its status to "approved". May trigger the post-author notification email. A "spam" or "trash" comment is rejected with a 409 "rest_comment_wrong_state" error (unspam or restore it first), because approving such a comment would skip the proper restore path and leave stale trash meta. Re-approving an already-approved comment is a benign no-op that reports the existing "approved" status. (By contrast, og-comments/unapprove-comment accepts any state, so the asymmetry is deliberate.) Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'og-core-comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to approve.', 'abilities-catalog' ),
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
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'The resulting comment status.', 'abilities-catalog' ),
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
				'screen'       => 'comment.php?action=editcomment&c={id}',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be logged in. The object-level
	 * `edit_comment` capability is enforced in {@see execute()} so a missing
	 * comment surfaces as a 404 and an unauthorized edit as a 403, rather than
	 * the Abilities API collapsing either into a generic permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by setting the comment status to `approve` via core
	 * `wp_set_comment_status()`, which leaves `comment_author_IP` untouched.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id and status, or a WP_Error.
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
		// (moderate_comments OR edit_comment). Placed before the no-op branch so an
		// unauthorized user is denied even when the status change would be a no-op.
		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this comment.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		// Wrong-state guard: a spam or trash comment must be restored through its own
		// path first. wp_set_comment_status( $id, 'approve' ) only flips
		// comment_approved; it does not clear _wp_trash_meta_status, so approving a
		// spam/trash comment skips the unspam/untrash restore and leaves stale meta.
		// Reject before mutating anything. Contrast og-comments/unapprove-comment, which
		// accepts any state.
		$current_status = (string) wp_get_comment_status( $id );
		if ( in_array( $current_status, array( 'spam', 'trash' ), true ) ) {
			$message = 'spam' === $current_status
				? __( 'This comment is marked as spam; unspam it before approving.', 'abilities-catalog' )
				: __( 'This comment is in the trash; restore it before it can be approved.', 'abilities-catalog' );
			return new WP_Error(
				'rest_comment_wrong_state',
				$message,
				array( 'status' => 409 )
			);
		}

		// Skip the no-op case: re-applying an unchanged status returns false from
		// wp_set_comment_status (0 rows), which would otherwise look like a failure.
		if ( 'approved' !== (string) wp_get_comment_status( $id ) ) {
			wp_set_comment_status( $id, 'approve' );
			if ( 'approved' !== (string) wp_get_comment_status( $id ) ) {
				return new WP_Error(
					'rest_comment_failed_edit',
					__( 'Updating comment status failed.', 'abilities-catalog' ),
					array( 'status' => 500 )
				);
			}
		}

		return array(
			'id'     => $id,
			'status' => $this->restStatus( (string) wp_get_comment_status( $id ) ),
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
