<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `comments/unapprove-comment`.
 *
 * Net-new framing over a comment status change to `hold` (the core status for an
 * unapproved comment), forced from any current state. Calls core
 * `wp_set_comment_status()` directly rather than the REST update path: the REST
 * path always rewrites `comment_author_IP` from `REMOTE_ADDR`, corrupting the
 * stored commenter IP on every status change (see backlog B3). The
 * `permission_callback` encodes the catalog capability: `moderate_comments` OR
 * object-level `edit_comment`.
 *
 * @since 0.2.0
 */
final class UnapproveComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'comments/unapprove-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Unapprove Comment', 'abilities-catalog' ),
			'description'         => __( 'Unapproves a comment, forcing its status to "hold" from any current state (also clears "spam" or "trash"). Reversible: re-approve with comments/approve-comment to restore "approved". Unapproving an already-held comment is a no-op that reports the existing "hold" status. Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to unapprove. Discover valid IDs with comments/list-comments.', 'abilities-catalog' ),
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
						'description' => __( 'The comment status before this call (e.g. "approved", "spam", "trash", "hold").', 'abilities-catalog' ),
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
	 * Permission check: `moderate_comments` OR object-level `edit_comment`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may moderate the comment.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		return $this->canModerate( absint( $input['id'] ?? 0 ) );
	}

	/**
	 * Whether the current user can moderate the given comment.
	 *
	 * @param int $id The comment ID.
	 * @return bool True if the user has moderate_comments or edit_comment on it.
	 */
	private function canModerate( int $id ): bool {
		return current_user_can( 'moderate_comments' ) || current_user_can( 'edit_comment', $id );
	}

	/**
	 * Executes the ability by setting the comment status to `hold` via core
	 * `wp_set_comment_status()`, which leaves `comment_author_IP` untouched.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id, resulting status, and prior status, or a WP_Error.
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

		$previous_status = (string) wp_get_comment_status( $id );

		// Skip the no-op case: re-applying an unchanged status returns false from
		// wp_set_comment_status (0 rows), which would otherwise look like a failure.
		if ( 'unapproved' !== $previous_status ) {
			wp_set_comment_status( $id, 'hold' );
			if ( 'unapproved' !== (string) wp_get_comment_status( $id ) ) {
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
