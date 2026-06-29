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
 * T2 destructive write ability: `og-comments/delete-comment`.
 *
 * Wraps `DELETE /wp/v2/comments/<id>` via the Abilities REST Adapter, permanently
 * deleting the comment (bypassing the trash). The caller passes only `id`;
 * {@see injectForce()} adds `force=true` at dispatch so the route deletes rather
 * than trashes. The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}, which un-nests the deleted comment's prior data
 * (`previous`); PII fields (author email/IP) are deliberately omitted.
 *
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set): the route's object-level capability (`delete_item_permissions_check` →
 * `check_edit_permission`: `moderate_comments` OR `edit_comment`) is STRICTER than
 * the old coarse `is_user_logged_in()` gate, so dropping that gate loses nothing
 * and does not widen access. The route surfaces the specific `rest_comment_invalid_id`
 * 404 / `rest_cannot_delete` 403 through `execute()` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/delete-comment';
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
				'label'           => __( 'Delete Comment', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a comment by ID, bypassing the trash. Requires the moderate_comments capability or edit permission on the comment. Discover comment IDs with og-comments/list-comments or og-comments/get-comment first. This cannot be undone.', 'abilities-catalog' ),
				'category'        => 'og-core-comments',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The comment ID to permanently delete. Find it with og-comments/list-comments or og-comments/get-comment.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectForce' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'     => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the comment was permanently deleted.', 'abilities-catalog' ),
						),
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The deleted comment ID.', 'abilities-catalog' ),
						),
						'post'        => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the post the deleted comment was on.', 'abilities-catalog' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'The ID of the deleted comment\'s parent, or 0 for a top-level comment.', 'abilities-catalog' ),
						),
						'author_name' => array(
							'type'        => 'string',
							'description' => __( 'The display name of the deleted comment author.', 'abilities-catalog' ),
						),
						'content'     => array(
							'type'        => 'string',
							'description' => __( 'The rendered content of the deleted comment.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'edit-comments.php',
				),
			)
		);
	}

	/**
	 * Injects `force=true` so the route permanently deletes (not trashes) the comment.
	 *
	 * Wired as the adapter's `input_callback`, it runs once at dispatch, after the
	 * ability validates input against its schema. The caller never supplies `force`;
	 * this pure transform adds it to the params the route receives.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with `force` set to true.
	 */
	public function injectForce( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the REST delete body to the catalog's six-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body (`{ deleted, previous: { ... } }`). `id` comes from the
	 * original ability input (the route's delete body does not echo it back);
	 * `content` is un-nested from `previous.content.rendered`; the other fields copy
	 * from `previous` with a type cast and a safe default. `$response` is part of the
	 * callback signature but unused here.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat deleted-comment fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();

		return array(
			'deleted'     => (bool) ( $data['deleted'] ?? false ),
			'id'          => (int) ( $input['id'] ?? 0 ),
			'post'        => (int) ( $previous['post'] ?? 0 ),
			'parent'      => (int) ( $previous['parent'] ?? 0 ),
			'author_name' => (string) ( $previous['author_name'] ?? '' ),
			'content'     => (string) ( $previous['content']['rendered'] ?? '' ),
		);
	}
}
