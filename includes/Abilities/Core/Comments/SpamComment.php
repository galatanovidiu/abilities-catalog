<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-comments/spam-comment`.
 *
 * Net-new framing over a comment status change to `spam`. Calls core
 * `wp_spam_comment()` directly rather than the REST update path. Two reasons:
 * the REST path always rewrites `comment_author_IP` from `REMOTE_ADDR`,
 * corrupting the stored commenter IP (see backlog B3); and `wp_spam_comment()`
 * saves the prior status in `_wp_trash_meta_status` so `og-comments/unspam-comment`
 * can restore it (a plain `wp_set_comment_status( $id, 'spam' )` would not). The
 * `permission_callback` is a coarse `is_user_logged_in()` gate; the object-level
 * `edit_comment` capability is enforced inside `execute()` so the caller receives
 * the specific `rest_comment_invalid_id` 404 / `rest_cannot_edit` 403 instead of a
 * generic permission failure (the Abilities API swallows a `WP_Error` returned
 * from a `permission_callback`; see backlog B4).
 *
 * @since 0.2.0
 */
final class SpamComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/spam-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Spam Comment', 'abilities-catalog' ),
			'description'         => __( 'Marks a comment as spam, setting its status to "spam". May trigger plugin spam hooks (for example Akismet). Re-spamming an already-spam comment is a no-op that reports the existing "spam" status. Returns the prior moderation status in "previous_status". Reversible via og-comments/unspam-comment. Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'og-core-comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to mark as spam.', 'abilities-catalog' ),
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
						'description' => __( 'The comment status before it was marked as spam. This is the status og-comments/unspam-comment restores to.', 'abilities-catalog' ),
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
	 * Executes the ability by marking the comment as spam via core
	 * `wp_spam_comment()`, which leaves `comment_author_IP` untouched and saves
	 * the prior status for `og-comments/unspam-comment` to restore.
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
		// (moderate_comments OR edit_comment). Placed before the no-op branch so an
		// unauthorized user is denied even when the status change would be a no-op.
		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this comment.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$previous_status = (string) wp_get_comment_status( $id );

		// Skip the no-op case: re-spamming would re-save _wp_trash_meta_status as
		// `spam`, breaking the prior status that unspam restores.
		if ( 'spam' !== $previous_status ) {
			wp_spam_comment( $id );
			if ( 'spam' !== (string) wp_get_comment_status( $id ) ) {
				return new WP_Error(
					'rest_comment_failed_edit',
					__( 'Updating comment status failed.', 'abilities-catalog' ),
					array( 'status' => 500 )
				);
			}
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
