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
 * T1 write ability: `comments/update-comment`.
 *
 * Wraps `POST /wp/v2/comments/<id>` via `rest_do_request()` and returns the
 * comment's id, content, status, author, date, and edit link. The
 * `permission_callback` encodes the
 * catalog capability: `moderate_comments` OR object-level `edit_comment`. The
 * REST route re-checks the capability underneath and sanitizes content.
 *
 * @since 0.2.0
 */
final class UpdateComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'comments/update-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Comment', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing comment\'s content, author name, author email, or date. Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to update.', 'abilities-catalog' ),
					),
					'content'      => array(
						'type'        => 'string',
						'description' => __( 'The new comment content (HTML allowed; sanitized by WordPress).', 'abilities-catalog' ),
					),
					'author_name'  => array(
						'type'        => 'string',
						'description' => __( 'The new author display name.', 'abilities-catalog' ),
					),
					'author_email' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'The new author email address.', 'abilities-catalog' ),
					),
					'date'         => array(
						'type'        => 'string',
						'format'      => 'date-time',
						'description' => __( 'The new comment date in site time (ISO 8601).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'edit_link' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'content'      => array(
						'type'        => 'string',
						'description' => __( 'The rendered comment content.', 'abilities-catalog' ),
					),
					'status'       => array(
						'type'        => 'string',
						'description' => __( 'The resulting comment status.', 'abilities-catalog' ),
					),
					'author_name'  => array(
						'type'        => 'string',
						'description' => __( 'The resulting author display name.', 'abilities-catalog' ),
					),
					'author_email' => array(
						'type'        => 'string',
						'description' => __( 'The resulting author email address.', 'abilities-catalog' ),
					),
					'date'         => array(
						'type'        => 'string',
						'description' => __( 'The resulting comment date in site time (ISO 8601).', 'abilities-catalog' ),
					),
					'edit_link'    => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the comment.', 'abilities-catalog' ),
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
	 * @return bool True if the current user may update the comment.
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
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment's id, content, status, author, date, edit link, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$request = new WP_REST_Request( 'POST', '/wp/v2/comments/' . $id );

		// Content passes through to the REST route, which sanitizes it.
		if ( isset( $input['content'] ) && '' !== $input['content'] ) {
			$request->set_param( 'content', (string) $input['content'] );
		}
		if ( isset( $input['author_name'] ) && '' !== $input['author_name'] ) {
			$request->set_param( 'author_name', sanitize_text_field( (string) $input['author_name'] ) );
		}
		if ( isset( $input['author_email'] ) && '' !== $input['author_email'] ) {
			$request->set_param( 'author_email', sanitize_email( (string) $input['author_email'] ) );
		}
		if ( isset( $input['date'] ) && '' !== $input['date'] ) {
			$request->set_param( 'date', (string) $input['date'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data       = rest_get_server()->response_to_data( $response, false );
		$comment_id = (int) ( $data['id'] ?? $id );

		return array(
			'id'           => $comment_id,
			'content'      => (string) ( $data['content']['rendered'] ?? '' ),
			'status'       => (string) ( $data['status'] ?? '' ),
			'author_name'  => (string) ( $data['author_name'] ?? '' ),
			'author_email' => (string) ( $data['author_email'] ?? '' ),
			'date'         => (string) ( $data['date'] ?? '' ),
			'edit_link'    => (string) get_edit_comment_link( $comment_id ),
		);
	}
}
