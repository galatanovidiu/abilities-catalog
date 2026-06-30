<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-comments/create-comment`.
 *
 * Wraps `POST /wp/v2/comments` via the Abilities REST Adapter and returns the new
 * comment's id, status, and link. A reply is the same call with a `parent`. The input
 * and output schemas are OVERRIDDEN to the catalog's narrow contract; the adapter
 * forwards the supplied fields to the route, {@see shapeInput()} applies the
 * author-identity moderation guard before dispatch, and {@see shapeOutput()} adds the
 * wp-admin `edit_link` via the non-REST {@see get_edit_comment_link()}.
 *
 * The wrapped create route enforces `read_post` (`rest_cannot_read_post`), and gates
 * `author` (`rest_comment_invalid_author`), `author_ip`, and `status`
 * (`rest_comment_invalid_status`) on `moderate_comments` — those surface their specific
 * errors instead of a generic permission failure. The route does NOT gate
 * `author_name`/`author_email` (it applies them unconditionally in
 * `prepare_item_for_database`), so this ability keeps a moderation guard for those two
 * identity fields in {@see shapeInput()} to prevent a non-moderator from spoofing the
 * comment author. Permission delegates to the route's own check (no `require_permission`
 * floor): the route's `create_item_permissions_check` requires a logged-in user, the
 * same coarse gate the source applied.
 *
 * @since 0.2.0
 */
final class CreateComment implements Ability {

	/**
	 * Author-identity fields the wrapped create route does NOT gate on
	 * `moderate_comments`. Setting either lets a caller spoof the stored comment
	 * author, so this ability enforces `moderate_comments` for them in shapeInput().
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/comments',
				'method'          => 'POST',
				'label'           => __( 'Create Comment', 'abilities-catalog' ),
				'description'     => __( 'Creates a comment on a post. Set parent to reply to another comment. Setting a moderation field (status, author, author_email, author_name) requires the moderate_comments capability.', 'abilities-catalog' ),
				'category'        => 'og-core-comments',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'edit-comments.php',
				),
			)
		);
	}

	/**
	 * Enforces the author-identity moderation guard before dispatch.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once at dispatch as the
	 * current user, after the ability validates input. The wrapped create route gates
	 * `author`/`author_ip`/`status` but applies `author_name`/`author_email`
	 * unconditionally, so a non-moderator could spoof the stored author. This guard
	 * returns the route's own `rest_comment_invalid_author` 403 for those two fields
	 * when the caller lacks `moderate_comments`. Other params are forwarded unchanged;
	 * the route sanitizes and validates each itself at dispatch.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed>|\WP_Error The params to dispatch, or a rejection.
	 */
	public function shapeInput( array $params ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			foreach ( self::UNGATED_AUTHOR_FIELDS as $field ) {
				if ( isset( $params[ $field ] ) && '' !== $params[ $field ] ) {
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

		return $params;
	}

	/**
	 * Reshapes the created REST comment body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST comment body. The id, status, and public `link` pass through, and
	 * `edit_link` is built from the non-REST {@see get_edit_comment_link()} (which the
	 * route does not return). `$input` and `$response` are part of the callback
	 * signature but unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST comment body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat comment fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data       = is_array( $data ) ? $data : array();
		$comment_id = (int) ( $data['id'] ?? 0 );

		return array(
			'id'        => $comment_id,
			'status'    => (string) ( $data['status'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'edit_link' => (string) get_edit_comment_link( $comment_id ),
		);
	}
}
