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
 * T2 non-destructive write ability: `og-menus/update-menu-item`.
 *
 * Wraps `POST /wp/v2/menu-items/<id>` via the Abilities REST Adapter to update a
 * classic menu item (`nav_menu_item` post). The menu-items controller extends the
 * posts controller; its update permission is the object-level `edit_post` capability
 * on the menu item ID, which `map_meta_cap` resolves to `edit_theme_options` for the
 * `nav_menu_item` post type. Input is OVERRIDDEN to the catalog's curated field set
 * (hiding raw REST fields such as `author`, `meta`, `date_gmt`) and coerced through
 * {@see coerceInput()}; the output is OVERRIDDEN to the catalog's flat 10-field set
 * through {@see shapeOutput()}. Permission delegates to the route's own check (no
 * `require_permission` floor): the route cap equals the catalog cap, so no floor is
 * needed, and the route's specific errors (`rest_post_invalid_id` 404) reach the caller.
 *
 * @since 0.3.0
 */
final class UpdateMenuItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/update-menu-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menu-items/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Menu Item', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing classic menu item by ID. Only the supplied fields change; an empty-string value for a text field clears it.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The menu item ID to update. Find item IDs via og-menus/list-menu-items.', 'abilities-catalog' ),
						),
						'title'      => array(
							'type'        => 'string',
							'description' => __( 'The menu item label.', 'abilities-catalog' ),
						),
						'url'        => array(
							'type'        => 'string',
							'description' => __( 'The URL the item points to.', 'abilities-catalog' ),
						),
						'type'       => array(
							'type'        => 'string',
							'enum'        => array( 'taxonomy', 'post_type', 'post_type_archive', 'custom' ),
							'description' => __( 'The family of object the item represents.', 'abilities-catalog' ),
						),
						'object'     => array(
							'type'        => 'string',
							'description' => __( 'The object type, such as "category", "post", or "page".', 'abilities-catalog' ),
						),
						'object_id'  => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'The database ID of the linked object, sourced per type (post ID for "post_type"/"post_type_archive", term ID for "taxonomy").', 'abilities-catalog' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'The menu item ID of the parent item, or 0 for a top-level item.', 'abilities-catalog' ),
						),
						'menu_order' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The position of the item within the menu.', 'abilities-catalog' ),
						),
						'menus'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent classic menu term ID this item belongs to. Find menu IDs via og-menus/list-classic-menus.', 'abilities-catalog' ),
						),
						'status'     => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'default'     => 'publish',
							'description' => __( 'The menu item post status. Core honors only "publish" and "draft"; any other value is normalized to "draft".', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'coerceInput' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => __( 'The menu item ID.', 'abilities-catalog' ),
						),
						'title'      => array(
							'type'        => 'string',
							'description' => __( 'The resolved menu item label.', 'abilities-catalog' ),
						),
						'url'        => array(
							'type'        => 'string',
							'description' => __( 'The resolved URL the item points to.', 'abilities-catalog' ),
						),
						'type'       => array(
							'type'        => 'string',
							'description' => __( 'The family of object the item represents.', 'abilities-catalog' ),
						),
						'object'     => array(
							'type'        => 'string',
							'description' => __( 'The object type, such as "category" or "page".', 'abilities-catalog' ),
						),
						'object_id'  => array(
							'type'        => 'integer',
							'description' => __( 'The database ID of the linked object.', 'abilities-catalog' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'description' => __( 'The menu item ID of the parent item, or 0 for a top-level item.', 'abilities-catalog' ),
						),
						'menu_order' => array(
							'type'        => 'integer',
							'description' => __( 'The position of the item within the menu.', 'abilities-catalog' ),
						),
						'menus'      => array(
							'type'        => 'integer',
							'description' => __( 'The classic menu term ID the item belongs to. 0 means the item is orphaned (not attached to any menu).', 'abilities-catalog' ),
						),
						'status'     => array(
							'type'        => 'string',
							'description' => __( 'The resulting menu item post status.', 'abilities-catalog' ),
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
					'screen'       => 'nav-menus.php',
				),
			)
		);
	}

	/**
	 * Coerces the curated input to the route's expected param types before dispatch.
	 *
	 * Wired as the adapter's `input_callback`. Text fields (`title`, `url`, `type`,
	 * `object`, `status`) are cast to string when present — including `''`, so the caller
	 * can clear a label or URL (core writes empty strings on update). Numeric fields
	 * (`object_id`, `parent`, `menu_order`, `menus`) are passed through `absint()`. The
	 * `id` path capture is passed through untouched for the adapter to substitute into the
	 * route path. Keys the caller did not send are left absent so no schema default is
	 * injected. The callback is pure (no instance state) and runs once per `execute()`.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The coerced params for dispatch.
	 */
	public function coerceInput( array $params ): array {
		foreach ( array( 'title', 'url', 'type', 'object', 'status' ) as $field ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}

			$params[ $field ] = (string) $params[ $field ];
		}

		foreach ( array( 'object_id', 'parent', 'menu_order', 'menus' ) as $field ) {
			if ( ! isset( $params[ $field ] ) ) {
				continue;
			}

			$params[ $field ] = absint( $params[ $field ] );
		}

		return $params;
	}

	/**
	 * Flattens the REST menu-item body to the catalog's 10-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the REST
	 * menu-item body. `title` is un-nested from its `{ rendered: ..., raw: ... }` shape
	 * (raw preferred over rendered when both are absent as a string); the other fields copy
	 * across with a type cast and a safe default. `$input` and `$response` are part of the
	 * callback signature but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST menu-item body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat menu-item fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? ( $title['raw'] ?? '' );
		}

		return array(
			'id'         => (int) ( $data['id'] ?? 0 ),
			'title'      => (string) $title,
			'url'        => (string) ( $data['url'] ?? '' ),
			'type'       => (string) ( $data['type'] ?? '' ),
			'object'     => (string) ( $data['object'] ?? '' ),
			'object_id'  => (int) ( $data['object_id'] ?? 0 ),
			'parent'     => (int) ( $data['parent'] ?? 0 ),
			'menu_order' => (int) ( $data['menu_order'] ?? 0 ),
			'menus'      => (int) ( $data['menus'] ?? 0 ),
			'status'     => (string) ( $data['status'] ?? '' ),
		);
	}
}
