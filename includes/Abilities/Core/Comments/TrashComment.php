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
 * T1 write ability: `og-comments/trash-comment`.
 *
 * Wraps `DELETE /wp/v2/comments/<id>` via the Abilities REST Adapter, moving the
 * comment to the trash (recoverable, not a permanent delete). The route's `force`
 * param defaults to false, so a plain DELETE trashes rather than permanently
 * deletes; no `force` is injected. The input schema is OVERRIDDEN to a single
 * required `id`, and the output is OVERRIDDEN to the catalog's flat field set
 * through {@see shapeOutput()}, which reads the pre-trash status from the
 * `_wp_trash_meta_status` comment meta that core records during the trash (this is
 * the value `og-comments/untrash-comment` restores).
 *
 * Permission delegates to the route's own check (no `require_permission` floor):
 * its `delete_item_permissions_check` enforces the object-level capability
 * (`moderate_comments` OR `edit_comment`), surfacing the specific
 * `rest_comment_invalid_id` 404 / `rest_cannot_delete` 403 instead of the
 * Abilities API collapsing it into a generic permission failure. When trashing is
 * disabled or unsupported the route returns a 501 `WP_Error`, and re-trashing an
 * already-trashed comment returns a 410 `rest_already_trashed` error; both surface
 * unchanged and this ability never calls `wp_trash_comment()` directly.
 *
 * @since 0.2.0
 */
final class TrashComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/trash-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/comments/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Trash Comment', 'abilities-catalog' ),
				'description'     => __( 'Moves a comment to the trash (recoverable). Requires the moderate_comments capability or edit permission on the comment. Discover comment IDs with og-comments/list-comments or og-comments/get-comment first. Returns a 501 error if trashing is disabled or unsupported on the site, and a 410 already-trashed error if the comment is already in the trash. Trashing a top-level note also trashes its child notes; standard comments do not cascade. Reversible: restore the comment to its prior status with og-comments/untrash-comment.', 'abilities-catalog' ),
				'category'        => 'og-core-comments',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The comment ID to trash. Find it with og-comments/list-comments or og-comments/get-comment.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'              => array(
							'type'        => 'integer',
							'description' => __( 'The comment ID.', 'abilities-catalog' ),
						),
						'status'          => array(
							'type'        => 'string',
							'description' => __( 'The resulting comment status (typically "trash").', 'abilities-catalog' ),
						),
						'previous_status' => array(
							'type'        => 'string',
							'description' => __( 'The raw comment_approved value before it was trashed ("1" for approved, "0" for unapproved/hold, "spam"). This is what core records for og-comments/untrash-comment to restore.', 'abilities-catalog' ),
						),
						'post'            => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the post the comment is on.', 'abilities-catalog' ),
						),
						'parent'          => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the comment\'s parent, or 0 for a top-level comment.', 'abilities-catalog' ),
						),
						'author_name'     => array(
							'type'        => 'string',
							'description' => __( 'The display name of the comment author.', 'abilities-catalog' ),
						),
						'type'            => array(
							'type'        => 'string',
							'description' => __( 'The comment type (for example "comment" or "note").', 'abilities-catalog' ),
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
					'screen'       => 'edit-comments.php?comment_status=trash',
				),
			)
		);
	}

	/**
	 * Reshapes the trashed REST comment body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST comment body. The pre-trash status comes from the `_wp_trash_meta_status`
	 * comment meta that core records during `wp_trash_comment()` (the raw
	 * `comment_approved` value, e.g. "1" for an approved comment); this is the value
	 * `og-comments/untrash-comment` restores. `$response` is part of the callback
	 * signature but unused — the body carries the rest of the shape.
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
			'id'              => $comment_id,
			'status'          => (string) ( $data['status'] ?? '' ),
			'previous_status' => (string) get_comment_meta( $comment_id, '_wp_trash_meta_status', true ),
			'post'            => (int) ( $data['post'] ?? 0 ),
			'parent'          => (int) ( $data['parent'] ?? 0 ),
			'author_name'     => (string) ( $data['author_name'] ?? '' ),
			'type'            => (string) ( $data['type'] ?? '' ),
		);
	}
}
