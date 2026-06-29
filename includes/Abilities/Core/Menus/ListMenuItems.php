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
 * Read ability: `og-menus/list-menu-items`.
 *
 * Wraps `GET /wp/v2/menu-items` via the Abilities REST Adapter and returns the
 * collection of classic menu items (`nav_menu_item` posts) plus its total counts.
 * The catalog gates this behind the admin `edit_theme_options` capability (kept as
 * a `require_permission` floor) and defaults to the `edit` context so callers get
 * the richer item fields (notably `menus`). Each row is projected by
 * {@see MenuListShaper} (in {@see shapeOutput()}) into a flat, closed summary; the
 * raw REST objects (`_links`, rendered `title` object, presentation fields) are
 * never returned. Read-only.
 *
 * @since 0.1.0
 */
final class ListMenuItems implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/list-menu-items';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/menu-items',
				'method'             => 'GET',
				'label'              => __( 'List Menu Items', 'abilities-catalog' ),
				'description'        => __( 'Lists classic menu items, optionally filtered by menu, with pagination.', 'abilities-catalog' ),
				'category'           => 'og-core-menus',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'menus'      => array(
							'type'        => 'integer',
							'description' => __( 'Limit results to items in the given classic menu term ID.', 'abilities-catalog' ),
						),
						'menu_order' => array(
							'type'        => 'integer',
							'description' => __( 'Limit results to items with the given menu order.', 'abilities-catalog' ),
						),
						'per_page'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => __( 'Number of items to return per page. When omitted, core returns up to 100 items.', 'abilities-catalog' ),
						),
						'page'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
						),
						'context'    => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'edit',
							'description' => __( 'Scope of the request. Defaults to "edit" so richer item fields are returned.', 'abilities-catalog' ),
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
							'items'       => MenuListShaper::menuItemSchema(),
							'description' => __( 'The list of menu items.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of menu items matching the query.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages available.', 'abilities-catalog' ),
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
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission floor: managing menus requires `edit_theme_options`.
	 *
	 * Mirrors the catalog's original cap. The route's own check still runs at
	 * dispatch.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Flattens the collection envelope into the catalog's summary-row shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Each row is flattened by
	 * {@see MenuListShaper::menuItemSummary()}; the totals carry through unchanged.
	 * `$input` and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat menu-item summary rows and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = MenuListShaper::menuItemSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? count( $rows ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
