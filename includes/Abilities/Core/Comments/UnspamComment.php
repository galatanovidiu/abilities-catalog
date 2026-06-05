<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `comments/unspam-comment`.
 *
 * Net-new framing over removing the spam mark. Calls core `wp_unspam_comment()`
 * directly rather than the REST update path, which always rewrites
 * `comment_author_IP` from `REMOTE_ADDR` and so corrupts the stored commenter IP
 * (see backlog B3). `wp_unspam_comment()` restores the status saved when the
 * comment was marked spam, falling back to `unapproved`/`hold` when that saved
 * status is missing. Accepts only a comment currently in `spam` status; on any
 * other status it returns a `rest_comment_wrong_state` 409 error without mutating
 * the comment, so a non-spam comment is never silently demoted. The
 * `permission_callback` is a coarse `is_user_logged_in()` gate; the object-level
 * `edit_comment` capability is enforced inside `execute()` so the caller receives
 * the specific `rest_comment_invalid_id` 404 / `rest_cannot_edit` 403 instead of a
 * generic permission failure (the Abilities API swallows a `WP_Error` returned
 * from a `permission_callback`; see backlog B4).
 *
 * @since 0.2.0
 */
final class UnspamComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'comments/unspam-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Unspam Comment', 'abilities-catalog' ),
			'description'         => __( 'Removes the spam mark from a comment, restoring the status saved when it was marked spam (falling back to "unapproved"/"hold" if that saved status is missing). Accepts only a comment currently in "spam" status; on any other status it returns a "rest_comment_wrong_state" 409 error without changing the comment. Returns the comment status before this call in "previous_status". Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to unmark as spam.', 'abilities-catalog' ),
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
						'description' => __( 'The resulting comment status.', 'abilities-catalog' ),
					),
					'previous_status' => array(
						'type'        => 'string',
						'description' => __( 'The comment status before this call. Always "spam" for a successful call, since the ability only accepts a comment in "spam" status.', 'abilities-catalog' ),
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
	 * Executes the ability by removing the spam mark via core
	 * `wp_unspam_comment()`, which leaves `comment_author_IP` untouched and
	 * restores the status saved when the comment was marked spam.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id, status, and prior status, or a WP_Error.
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
		// (moderate_comments OR edit_comment). Placed before the always-runs
		// wp_unspam_comment() so an unauthorized user cannot trigger the restore.
		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this comment.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		// Wrong-state guard: only a comment currently marked spam can be unspammed.
		// Calling wp_unspam_comment() on a non-spam comment silently demotes it to
		// hold (data loss), so reject before mutating anything.
		if ( 'spam' !== wp_get_comment_status( $id ) ) {
			return new WP_Error(
				'rest_comment_wrong_state',
				__( 'This comment is not marked as spam, so it cannot be unspammed.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		$previous_status = (string) wp_get_comment_status( $id );

		// No fixed target status: wp_unspam_comment restores the saved prior status
		// or falls back to hold. Its own no-op restore is harmless, so it is always
		// called and the resulting status is reported.
		wp_unspam_comment( $id );

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
