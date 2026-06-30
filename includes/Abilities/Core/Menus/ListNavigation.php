<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\MenuListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-menus/list-navigation`.
 *
 * Wraps `GET /wp/v2/navigation` via the Abilities REST Adapter and returns the
 * collection of block-based navigation menus (`wp_navigation` post type) plus its
 * total counts. The adapter wraps a collection route as a
 * `{ items, total, total_pages }` envelope; {@see shapeOutput()} projects each row
 * through {@see MenuListShaper::navigationSummary()} into a flat, closed summary and
 * preserves the totals. The serialized block body (`content`), `_links`, and
 * GMT-duplicate dates are never returned (the body lives behind
 * `og-menus/get-navigation`).
 *
 * The input and output schemas are OVERRIDDEN to the catalog's narrow contract.
 * Unlike most converted reads, a `require_permission` floor IS set: the source
 * ability gated on `edit_theme_options`, but the route's own list check only
 * restricts non-public statuses in the `view` context, so published navigation
 * menus would otherwise be readable by any visitor. The floor keeps the strict
 * `edit_theme_options` guard the catalog has always enforced. Read-only.
 *
 * @since 0.1.0
 */
final class ListNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/list-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/navigation',
				'method'             => 'GET',
				'label'              => __( 'List Navigation Menus', 'abilities-catalog' ),
				'description'        => __( 'Lists block-based navigation menus with pagination.', 'abilities-catalog' ),
				'category'           => 'og-core-menus',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
							'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
						),
						'context'  => array(
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
					'required'             => array( 'items', 'total', 'total_pages' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => MenuListShaper::navigationItemSchema(),
							'description' => __( 'The list of navigation menus.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of navigation menus matching the query.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages available.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback'    => array( $this, 'shapeOutput' ),
				'require_permission' => array( $this, 'requirePermission' ),
				'meta'               => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission floor: managing menus requires `edit_theme_options`.
	 *
	 * Wired as the adapter's `require_permission`. The route's own list check
	 * (`view` context) would expose published navigation menus to any visitor;
	 * this floor keeps the strict guard the catalog has always enforced. The
	 * route's check still runs at dispatch and stays the authority.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True if the current user may read navigation menus.
	 */
	public function requirePermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Projects the REST navigation collection into the catalog's flat row set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over
	 * the `{ items, total, total_pages }` envelope the adapter builds for a
	 * collection route. Each row is mapped through
	 * {@see MenuListShaper::navigationSummary()} (which drops the serialized block
	 * body, `_links`, and GMT dates), and the totals pass through unchanged.
	 * `$input` and `$response` are part of the callback signature but unused — the
	 * envelope carries everything this shape needs.
	 *
	 * @param mixed               $data     The collection envelope (`items`, `total`, `total_pages`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped collection and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = is_array( $data['items'] ?? null ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = MenuListShaper::navigationSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? 0 ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
