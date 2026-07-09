<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write ability: `og-comments/update-comment`.
 *
 * Wraps `POST /wp/v2/comments/<id>` via the Abilities REST Adapter. The input and
 * output schemas are OVERRIDDEN to the catalog's narrow contract (a subset of the
 * route's update fields in, a flat field set out); the adapter forwards the
 * supplied fields to the route and {@see shapeOutput()} reshapes the body, adding
 * the wp-admin `edit_link` via the non-REST {@see get_edit_comment_link()}.
 *
 * The route sanitizes each field itself at dispatch (`sanitize_text_field` for
 * `author_name`, `check_comment_author_email` for `author_email`) and ignores an
 * empty `date`, so no `input_callback` is needed — the adapter forwards exactly the
 * keys the caller supplied. Permission delegates to the route's own check (no
 * `require_permission` floor): its `update_item_permissions_check` enforces the
 * object-level capability (`moderate_comments` OR `edit_comment`), surfacing the
 * specific `rest_comment_invalid_id` 404 / `rest_cannot_edit` 403 instead of the
 * Abilities API collapsing it into a generic permission failure.
 *
 * @since 0.2.0
 */
final class UpdateComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/update-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/comments/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Comment', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing comment\'s content, author name, author email, or date. Requires moderate_comments or edit permission on the comment.', 'abilities-catalog' ),
				'category'        => 'og-core-comments',
				'input_schema'    => array(
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
							'description' => __( 'The new author email address. Validated by core; a malformed value returns rest_invalid_email.', 'abilities-catalog' ),
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
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'comment.php?action=editcomment&c={id}',
				),
			)
		);
	}

	/**
	 * Reshapes the updated REST comment body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST comment body. `content` is un-nested from its `{ rendered: ... }` shape,
	 * and `edit_link` is built from the non-REST {@see get_edit_comment_link()} (which
	 * the route does not return). `$response` is part of the callback signature but
	 * unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST comment body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat comment fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data       = is_array( $data ) ? $data : array();
		$comment_id = (int) ( $data['id'] ?? absint( $input['id'] ?? 0 ) );

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
