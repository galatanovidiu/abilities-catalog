<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\MenuListShaper;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-menus/list-menu-items`.
 *
 * Wraps `GET /wp/v2/menu-items` via `rest_do_request()` and returns the
 * collection of classic menu items (`nav_menu_item` posts) plus its total
 * counts. The catalog gates this behind the admin `edit_theme_options`
 * capability and defaults to the `edit` context so callers get the richer
 * item fields (notably `menus`). Each row is projected by {@see MenuListShaper}
 * into a flat, closed summary; the raw REST objects (`_links`, rendered `title`
 * object, presentation fields) are never returned. Read-only.
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
		return array(
			'label'               => __( 'List Menu Items', 'abilities-catalog' ),
			'description'         => __( 'Lists classic menu items, optionally filtered by menu, with pagination.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: managing menus requires `edit_theme_options`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read menu items.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and totals, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/menu-items' );
		$request->set_param( 'context', $input['context'] ?? 'edit' );

		if ( isset( $input['menus'] ) ) {
			$request->set_param( 'menus', absint( $input['menus'] ) );
		}
		if ( isset( $input['menu_order'] ) ) {
			$request->set_param( 'menu_order', absint( $input['menu_order'] ) );
		}
		if ( isset( $input['per_page'] ) ) {
			$request->set_param( 'per_page', absint( $input['per_page'] ) );
		}
		if ( isset( $input['page'] ) ) {
			$request->set_param( 'page', absint( $input['page'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$items   = rest_get_server()->response_to_data( $response, false );
		$headers = $response->get_headers();

		$rows = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = MenuListShaper::menuItemSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}
}
