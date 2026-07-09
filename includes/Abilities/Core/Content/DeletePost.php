<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-content/delete-post`.
 *
 * Wraps `DELETE /wp/v2/posts/<id>` via the Abilities REST Adapter, permanently
 * deleting the post (bypassing the Trash). The input schema is OVERRIDDEN to a
 * single required `id`; `force=true` is injected post-validation by
 * {@see injectForce()}, so the caller never passes it. The output is OVERRIDDEN
 * to the catalog's flat `{ deleted, id, title }` set through {@see shapeOutput()}.
 * Permission delegates to the route's own object-level `delete_post` check (no
 * `require_permission` floor is set); this ability never calls `wp_delete_post()`
 * directly and surfaces the route's `WP_Error` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeletePost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/delete-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Post', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a post by ID, bypassing the Trash. This cannot be undone. To remove a post recoverably, use `og-content/trash-post` instead.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The post ID to permanently delete. Obtain it from a list/get content ability (e.g. `og-content/list-posts` or `og-content/get-post`).', 'abilities-catalog' ),
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
						'deleted' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the post was permanently deleted.', 'abilities-catalog' ),
						),
						'id'      => array(
							'type'        => 'integer',
							'description' => __( 'The deleted post ID.', 'abilities-catalog' ),
						),
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'The title of the deleted post, so a human can confirm what was removed. No edit_link is returned because the post no longer exists.', 'abilities-catalog' ),
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
					'screen'       => 'edit.php',
				),
			)
		);
	}

	/**
	 * Injects `force=true` so the route deletes permanently rather than trashing.
	 *
	 * Wired as the adapter's `input_callback`, it runs once after the ability
	 * validates input against its schema and before dispatch. The caller never
	 * passes `force`; the input schema does not expose it.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with `force` forced to true.
	 */
	public function injectForce( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the REST delete response to the catalog's `{ deleted, id, title }` set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over
	 * the REST delete body. `deleted` and the deleted post's title (from the
	 * response's `previous` object) come from `$data`; `id` echoes the original
	 * input so the caller sees what was removed. No `edit_link` is returned — the
	 * post no longer exists. `$response` is part of the callback signature but
	 * unused here.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat delete result.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => (int) ( $input['id'] ?? 0 ),
			'title'   => (string) ( $data['previous']['title']['rendered'] ?? '' ),
		);
	}
}
