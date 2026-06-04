<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `comments/trash-comment`.
 *
 * Wraps `DELETE /wp/v2/comments/<id>` with `force=false` via `rest_do_request()`,
 * moving the comment to the trash (recoverable, not a permanent delete). The
 * `permission_callback` encodes the catalog capability: object-level
 * `edit_comment`. When trashing is disabled (no trash days configured) the REST
 * route returns a 501 `WP_Error`, which this ability surfaces unchanged; it
 * never calls `wp_trash_comment()` directly.
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
			'description'         => __( 'Moves a comment to the trash (recoverable). Requires edit permission on the comment. Returns a 501 error if trashing is disabled on the site.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The comment ID to trash.', 'abilities-catalog' ),
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
						'description' => __( 'The resulting comment status (typically "trash").', 'abilities-catalog' ),
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
	 * Permission check: object-level `edit_comment`.
	 *
	 * Mirrors the REST `delete_item_permissions_check`, which gates on
	 * `check_edit_permission` (`moderate_comments` OR `edit_comment`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may trash the comment.
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
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=false` (trash, not permanent delete).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id and status, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $id );
		$request->set_param( 'force', false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'     => (int) ( $data['id'] ?? $id ),
			'status' => (string) ( $data['status'] ?? '' ),
		);
	}
}
