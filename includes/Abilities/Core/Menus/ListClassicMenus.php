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
 * Read ability: `og-menus/list-classic-menus`.
 *
 * Wraps `GET /wp/v2/menus` via `rest_do_request()` and returns the collection
 * of classic menus (`nav_menu` terms) plus its total counts. Each row is
 * projected by {@see MenuListShaper} into a flat, closed summary; the raw REST
 * objects (`_links`, `meta`, `locations`, `auto_add`) are never returned.
 * Read-only.
 *
 * @since 0.1.0
 */
final class ListClassicMenus implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/list-classic-menus';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Classic Menus', 'abilities-catalog' ),
			'description'         => __( 'Lists classic (nav_menu term) menus with pagination.', 'abilities-catalog' ),
			'category'            => 'og-core-menus',
			'input_schema'        => array(
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
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items'       => array(
						'type'        => 'array',
						'items'       => MenuListShaper::classicMenuItemSchema(),
						'description' => __( 'The list of classic menus.', 'abilities-catalog' ),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'Total number of classic menus matching the query.', 'abilities-catalog' ),
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
	 * @return bool True if the current user may read classic menus.
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

		$request = new WP_REST_Request( 'GET', '/wp/v2/menus' );
		$request->set_param( 'context', $input['context'] ?? 'view' );

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

			$rows[] = MenuListShaper::classicMenuSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}
}
