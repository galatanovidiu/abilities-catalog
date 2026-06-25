<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-comments/create-comment`.
 *
 * Wraps `POST /wp/v2/comments` via `rest_do_request()` and returns the new
 * comment's id, status, and link. A reply is the same call with a `parent`.
 * The `permission_callback` is a coarse `is_user_logged_in()` gate. The wrapped
 * create route enforces `read_post` (`rest_cannot_read_post`), and gates `author`
 * (`rest_comment_invalid_author`), `author_ip`, and `status`
 * (`rest_comment_invalid_status`) on `moderate_comments` — those surface their
 * specific errors instead of a generic permission failure (see backlog B4). The
 * route does NOT gate `author_name`/`author_email` (it applies them unconditionally
 * in `prepare_item_for_database`), so this ability keeps a moderation guard for
 * those two identity fields in `execute()` to prevent a non-moderator from spoofing
 * the comment author.
 *
 * @since 0.2.0
 */
final class CreateComment implements Ability {

	/**
	 * Author-identity fields the wrapped create route does NOT gate on
	 * `moderate_comments`. Setting either lets a caller spoof the stored comment
	 * author, so this ability enforces `moderate_comments` for them in execute().
	 * The route already gates `author`, `author_ip`, and `status` itself.
	 *
	 * @var string[]
	 */
	private const UNGATED_AUTHOR_FIELDS = array( 'author_name', 'author_email' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/create-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Comment', 'abilities-catalog' ),
			'description'         => __( 'Creates a comment on a post. Set parent to reply to another comment. Setting a moderation field (status, author, author_email, author_name) requires the moderate_comments capability.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post'         => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post to comment on.', 'abilities-catalog' ),
					),
					'parent'       => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the parent comment when replying. Defaults to 0 (top-level).', 'abilities-catalog' ),
					),
					'content'      => array(
						'type'        => 'string',
						'description' => __( 'The comment content (HTML allowed; sanitized by WordPress).', 'abilities-catalog' ),
					),
					'author'       => array(
						'type'        => 'integer',
						'description' => __( 'The author user ID. Requires the moderate_comments capability.', 'abilities-catalog' ),
					),
					'author_name'  => array(
						'type'        => 'string',
						'description' => __( 'The author display name. Requires the moderate_comments capability.', 'abilities-catalog' ),
					),
					'author_email' => array(
						'type'        => 'string',
						'description' => __( 'The author email address. Requires the moderate_comments capability.', 'abilities-catalog' ),
					),
					'status'       => array(
						'type'        => 'string',
						'enum'        => array( 'approve', 'hold' ),
						'description' => __( 'The initial moderation status — exactly "approve" (publish immediately) or "hold" (queue for moderation); not "approved", "publish", "spam", or a number. Only settable with the moderate_comments capability; omit it to let WordPress decide from the discussion settings.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'link', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The new comment ID.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting comment status.', 'abilities-catalog' ),
					),
					'link'      => array(
						'type'        => 'string',
						'description' => __( 'The public permalink to the comment.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the comment. Surface this so a moderator can review a held comment.', 'abilities-catalog' ),
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
				'screen'       => 'edit-comments.php',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be logged in. `read_post`, and the
	 * `author`/`author_ip`/`status` moderation gates, are enforced by the wrapped
	 * create route so their specific errors reach the caller (see backlog B4). The
	 * `author_name`/`author_email` guard the route omits is applied in
	 * {@see execute()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new comment's id, status, link, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		// The wrapped create route gates author/author_ip/status but applies
		// author_name/author_email unconditionally, so a non-moderator could spoof
		// the stored author. Enforce moderate_comments for those two fields here.
		if ( ! current_user_can( 'moderate_comments' ) ) {
			foreach ( self::UNGATED_AUTHOR_FIELDS as $field ) {
				if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) {
					return new WP_Error(
						'rest_comment_invalid_author',
						sprintf(
							/* translators: %s: Request parameter name. */
							__( "Sorry, you are not allowed to edit '%s' for comments.", 'abilities-catalog' ),
							$field
						),
						array( 'status' => rest_authorization_required_code() )
					);
				}
			}
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );

		if ( ! empty( $input['post'] ) ) {
			$request->set_param( 'post', absint( $input['post'] ) );
		}
		if ( ! empty( $input['parent'] ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
		}
		// Content passes through to the REST route, which sanitizes it.
		if ( isset( $input['content'] ) && '' !== $input['content'] ) {
			$request->set_param( 'content', (string) $input['content'] );
		}
		if ( ! empty( $input['author'] ) ) {
			$request->set_param( 'author', absint( $input['author'] ) );
		}
		if ( isset( $input['author_name'] ) && '' !== $input['author_name'] ) {
			$request->set_param( 'author_name', sanitize_text_field( (string) $input['author_name'] ) );
		}
		// Pass the raw value: the wrapped route's `check_comment_author_email`
		// sanitize_callback validates it and returns `rest_invalid_email` on a
		// malformed address. Pre-running `sanitize_email()` here would strip an
		// invalid value to '' and the guard above would then drop it, hiding core's
		// validation error (wrap, don't reimplement).
		if ( isset( $input['author_email'] ) && '' !== $input['author_email'] ) {
			$request->set_param( 'author_email', (string) $input['author_email'] );
		}
		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$request->set_param( 'status', sanitize_key( (string) $input['status'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data       = rest_get_server()->response_to_data( $response, false );
		$comment_id = (int) ( $data['id'] ?? 0 );

		return array(
			'id'        => $comment_id,
			'status'    => (string) ( $data['status'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'edit_link' => (string) get_edit_comment_link( $comment_id ),
		);
	}
}
