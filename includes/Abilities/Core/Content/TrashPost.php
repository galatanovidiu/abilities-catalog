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
 * T1 write ability: `og-content/trash-post`.
 *
 * Wraps `DELETE /wp/v2/posts/<id>` via the Abilities REST Adapter. The route's
 * `force` param defaults to false, so a plain DELETE moves the post to Trash
 * (recoverable) rather than deleting it permanently; no `force` is injected. The
 * input schema is OVERRIDDEN to a single required `id`, and the output is
 * OVERRIDDEN to the catalog's flat `{ id, title, status, previous_status }` set
 * through {@see shapeOutput()}, which reads the pre-trash status from the
 * `_wp_trash_meta_status` post meta that core records during the trash.
 *
 * Permission delegates to the route's own object-level `delete_post` check (no
 * `require_permission` floor): a denial surfaces the route's real
 * `rest_cannot_delete` 403 instead of the Abilities API collapsing it into a
 * generic permission failure. When Trash is disabled or unsupported
 * (`EMPTY_TRASH_DAYS` is 0, or the `rest_post_trashable` filter returns false) the
 * route returns a 501 `rest_trash_not_supported` error; trashing an
 * already-trashed post returns a 410 `rest_already_trashed` error. Both are
 * surfaced unchanged; this ability never calls `wp_trash_post()` directly.
 *
 * @since 0.2.0
 */
final class TrashPost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/trash-post';
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
				'label'           => __( 'Trash Post', 'abilities-catalog' ),
				'description'     => __( 'Moves a post to the Trash by ID. The post is recoverable. Fails if Trash is disabled or unsupported on the site, or if the post is already in the Trash.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The post ID to move to Trash.', 'abilities-catalog' ),
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
							'description' => __( 'The post ID.', 'abilities-catalog' ),
						),
						'title'           => array(
							'type'        => 'string',
							'description' => __( 'The rendered title of the trashed post, so a human can confirm what was moved to Trash.', 'abilities-catalog' ),
						),
						'status'          => array(
							'type'        => 'string',
							'description' => __( 'The resulting post status (trash). The post is recoverable from Posts → Trash. No edit_link is returned: a trashed post cannot be opened in the editor (wp-admin returns HTTP 409); it must be restored first.', 'abilities-catalog' ),
						),
						'previous_status' => array(
							'type'        => 'string',
							'description' => __( 'The post status before trashing (e.g. publish, draft). Core records this so a later restore re-applies it; use it to tell whether a live post was taken offline or only a draft was trashed.', 'abilities-catalog' ),
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
					'screen'       => 'edit.php',
				),
			)
		);
	}

	/**
	 * Reshapes the trashed REST post body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST post body. The pre-trash status comes from the `_wp_trash_meta_status`
	 * post meta that core records during `wp_trash_post()`; returning it lets the
	 * caller tell whether a live post was taken offline or only a draft was trashed,
	 * and what a later restore will re-expose. No `edit_link` is returned: a trashed
	 * post cannot be edited (wp-admin returns HTTP 409 until it is restored).
	 * `$response` is part of the callback signature but unused.
	 *
	 * @param mixed               $data     The REST post body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat trashed-post fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data    = is_array( $data ) ? $data : array();
		$post_id = (int) ( $data['id'] ?? absint( $input['id'] ?? 0 ) );

		return array(
			'id'              => $post_id,
			'title'           => (string) ( $data['title']['rendered'] ?? '' ),
			'status'          => (string) ( $data['status'] ?? 'trash' ),
			'previous_status' => (string) get_post_meta( $post_id, '_wp_trash_meta_status', true ),
		);
	}
}
