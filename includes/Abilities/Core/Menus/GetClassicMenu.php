<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-menus/get-classic-menu`.
 *
 * Wraps `GET /wp/v2/menus/<id>` via the Abilities REST Adapter and shapes the
 * response into a flat field set for a single classic menu (`nav_menu` term).
 * The input and output schemas are OVERRIDDEN to the catalog's narrow contract;
 * the adapter dispatches the route and {@see shapeOutput()} reshapes the body,
 * adding the `count` field via the non-REST {@see wp_get_nav_menu_object()}
 * (the REST menus response never emits `count`). Permission delegates to the
 * route's own check (no `require_permission` floor): the menus controller
 * requires `edit_theme_options`, which matches the catalog capability — it is
 * not stricter — so the route's `rest_cannot_view` surfaces directly instead of
 * the Abilities API collapsing it into a generic permission failure. Read-only.
 *
 * @since 0.2.0
 */
final class GetClassicMenu implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/get-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menus/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Classic Menu', 'abilities-catalog' ),
				'description'     => __( 'Returns a single classic (nav_menu term) menu by ID.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The classic menu term ID. Use og-menus/list-classic-menus to discover the ID.', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The menu name.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The menu slug.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The menu description.', 'abilities-catalog' ),
						),
						'count'       => array(
							'type'        => 'integer',
							'description' => __( 'The number of items in the menu.', 'abilities-catalog' ),
						),
						'meta'        => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'The menu meta.', 'abilities-catalog' ),
						),
						'locations'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Theme location slugs this menu is currently assigned to.', 'abilities-catalog' ),
						),
						'auto_add'    => array(
							'type'        => 'boolean',
							'description' => __( 'Whether new top-level pages are automatically added to this menu.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
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
	 * Maps the REST menu body to the catalog's flat output shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. The empty
	 * `meta` map is cast to an object so it serializes as `{}`, `locations` is
	 * re-indexed, and `count` is derived from the non-REST
	 * {@see wp_get_nav_menu_object()} (the REST menus response never emits it).
	 * `$input['id']` is the fallback id. `$response` is part of the callback
	 * signature but unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST menu body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat classic menu fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = (int) ( $data['id'] ?? absint( $input['id'] ?? 0 ) );

		// The REST menus response never emits `count`; derive it from the term object.
		$menu_obj = wp_get_nav_menu_object( $id );

		return array(
			'id'          => $id,
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'count'       => $menu_obj ? (int) $menu_obj->count : 0,
			'meta'        => isset( $data['meta'] ) && is_array( $data['meta'] ) && array() !== $data['meta'] ? $data['meta'] : (object) array(),
			'locations'   => isset( $data['locations'] ) && is_array( $data['locations'] ) ? array_values( $data['locations'] ) : array(),
			'auto_add'    => (bool) ( $data['auto_add'] ?? false ),
		);
	}
}
