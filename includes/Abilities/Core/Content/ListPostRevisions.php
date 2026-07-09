<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ContentListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-content/list-post-revisions`.
 *
 * Wraps `GET /wp/v2/posts/<parent>/revisions` via the Abilities REST Adapter. The
 * input schema is OVERRIDDEN to the catalog's curated set (`parent` required,
 * `context`); the path capture `parent` is supplied as ordinary input. The route is
 * a `get_items` collection, so the adapter wraps the body as the
 * `{ items, total, total_pages }` envelope from the `X-WP-Total` headers. The
 * envelope items are then reshaped to the catalog's flat revision rows through
 * {@see shapeOutput()}. Permission delegates to the route's own `edit_post` check on
 * the parent (no `require_permission` floor is set), so a missing parent surfaces as
 * the route's real `rest_post_invalid_parent` 404, not a masked permission failure.
 *
 * @since 0.1.0
 */
final class ListPostRevisions implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/list-post-revisions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/posts/(?P<parent>[\d]+)/revisions',
				'method'          => 'GET',
				'label'           => __( 'List Post Revisions', 'abilities-catalog' ),
				'description'     => __( 'Lists the saved revisions of a post by its parent post ID. Requires edit access to the parent post.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'parent'  => array(
							'type'        => 'integer',
							'description' => __( 'The parent post ID. Use og-content/list-posts, og-content/list-pages, or og-content/get-post to find it.', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'parent' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => ContentListShaper::revisionItemSchema(),
							'description' => __( 'The list of revisions as flat summary rows. Use og-content/get-post-revision for a single revision body.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of revisions.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of result pages available.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Reshapes the collection envelope's items to the catalog's flat revision rows.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter built from the
	 * `X-WP-Total`/`X-WP-TotalPages` headers. Each REST revision item is mapped through
	 * {@see ContentListShaper::revisionSummary()} to its flat summary; `total` and
	 * `total_pages` pass through unchanged. `$input` and `$response` are part of the
	 * callback signature but unused here — the envelope carries everything this shape needs.
	 *
	 * @param mixed               $data     The collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The envelope with flat revision rows.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		return array(
			'items'       => array_map( array( ContentListShaper::class, 'revisionSummary' ), $items ),
			'total'       => (int) ( $data['total'] ?? 0 ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
