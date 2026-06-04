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
 * T1 write ability: `comments/unspam-comment`.
 *
 * Net-new framing over `POST /wp/v2/comments/<id>` with a fixed `status` of
 * `unspam`. The controller restores the status saved when the comment was
 * marked spam, falling back to `unapproved`/`hold` when that saved status is
 * missing. Should only be called on a comment currently in `spam` status.
 * Takes only the comment ID. The `permission_callback` encodes the catalog
 * capability: `moderate_comments` OR object-level `edit_comment`. The REST
 * route re-checks the capability and applies the status change.
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
			'description'         => __( 'Removes the spam mark from a comment, restoring the status saved when it was marked spam (falling back to "unapproved"/"hold" if that saved status is missing). Should only be called on a comment currently in "spam" status; on a non-spam comment it silently moves the comment to "hold". Returns the comment status before this call in "previous_status". Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
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
						'description' => __( 'The comment status before this call. Expected to be "spam" for correct use; any other value indicates the ability was called on a non-spam comment.', 'abilities-catalog' ),
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
	 * Executes the ability by dispatching the internal REST update request with
	 * a fixed `status` of `unspam`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id, status, and prior status, or the REST error.
	 */
	public function execute( $input ) {
		$input           = is_array( $input ) ? $input : array();
		$id              = absint( $input['id'] ?? 0 );
		$previous_status = (string) wp_get_comment_status( $id );
		$request         = new WP_REST_Request( 'POST', '/wp/v2/comments/' . $id );
		$request->set_param( 'status', 'unspam' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'              => (int) ( $data['id'] ?? $id ),
			'status'          => (string) ( $data['status'] ?? '' ),
			'previous_status' => $previous_status,
		);
	}
}
