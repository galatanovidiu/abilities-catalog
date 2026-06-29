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
 * Read ability: `og-content/list-pages`.
 *
 * Wraps `GET /wp/v2/pages` via the Abilities REST Adapter and returns the
 * collection plus its total counts. Read-only; REST enforces per-row visibility
 * underneath. The bare route is public for published pages, so a
 * `require_permission` floor keeps the catalog's original cap (logged-in for the
 * view/published path; `edit_pages` for edit context or a non-public status).
 * {@see shapeOutput()} flattens each row through {@see ContentListShaper} and
 * preserves the `{ items, total, total_pages }` envelope.
 *
 * @since 0.1.0
 */
final class ListPages implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/list-pages';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/pages',
				'method'             => 'GET',
				'label'              => __( 'List Pages', 'abilities-catalog' ),
				'description'        => __( 'Lists pages with optional search, status, author, parent, ordering, and pagination filters.', 'abilities-catalog' ),
				'category'           => 'og-core-content',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to those matching a search term.', 'abilities-catalog' ),
						),
						'status'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to a page status (e.g. "publish", "draft").', 'abilities-catalog' ),
						),
						'author'     => array(
							'type'        => 'integer',
							'description' => __( 'Limit results to a given author user ID.', 'abilities-catalog' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'description' => __( 'Limit results to pages with a given parent page ID.', 'abilities-catalog' ),
						),
						'menu_order' => array(
							'type'        => 'integer',
							'description' => __( 'Limit results to pages with a given menu order value.', 'abilities-catalog' ),
						),
						'per_page'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
							'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
						),
						'page'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
						),
						'orderby'    => array(
							'type'        => 'string',
							'enum'        => array( 'author', 'date', 'id', 'include', 'modified', 'parent', 'relevance', 'slug', 'include_slugs', 'title', 'menu_order' ),
							'description' => __( 'Field to sort by (e.g. "date", "title", "menu_order").', 'abilities-catalog' ),
						),
						'order'      => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => __( 'Sort direction.', 'abilities-catalog' ),
						),
						'context'    => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => ContentListShaper::pageItemSchema(),
							'description' => __( 'The list of pages as flat summary rows. Use og-content/get-page for a single page body.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages matching the query.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of result pages available.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'require_permission' => array( $this, 'requirePermission' ),
				'output_callback'    => array( $this, 'shapeOutput' ),
				'meta'               => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'keywords'     => array( 'search pages', 'find pages', 'list pages', 'all pages', 'pages by author', 'pages by status', 'draft pages', 'page tree' ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission floor: public for published pages; `edit_pages` for edit-context
	 * or when a non-public status is requested.
	 *
	 * Mirrors the catalog's original cap so the conversion does not widen the bare
	 * route (which serves published pages to anonymous callers). The route's own
	 * per-row visibility check still runs at dispatch.
	 *
	 * @param mixed $input The raw ability input.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		$context = $input['context'] ?? 'view';
		$status  = isset( $input['status'] ) ? (string) $input['status'] : 'publish';

		if ( 'edit' === $context || ( 'publish' !== $status && '' !== $status ) ) {
			return current_user_can( 'edit_pages' );
		}

		return is_user_logged_in();
	}

	/**
	 * Flattens the collection envelope into the catalog's summary-row shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Each row is flattened by
	 * {@see ContentListShaper::pageSummary()}; the totals carry through unchanged.
	 * `$input` and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat page summary rows and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = ContentListShaper::pageSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? count( $rows ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
