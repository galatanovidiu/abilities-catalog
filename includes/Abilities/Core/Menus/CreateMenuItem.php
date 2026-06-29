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
 * T2 non-destructive write ability: `og-menus/create-menu-item`.
 *
 * Wraps `POST /wp/v2/menu-items` via the Abilities REST Adapter to create a
 * classic menu item (`nav_menu_item` post). The input schema is OVERRIDDEN to a
 * curated closed set (title, url, type, object, object_id, parent, menu_order,
 * menus, status) so the raw REST fields stay hidden; {@see coerceInput()} mirrors
 * the old `absint()` coercions for the integer fields. The output is OVERRIDDEN to
 * the catalog's flat 10-field set through {@see shapeOutput()}.
 *
 * The menu-items controller extends the posts controller; the `nav_menu_item` post
 * type maps `create_posts` / `edit_posts` and `edit_others_posts` to
 * `edit_theme_options`, so the route requires `edit_theme_options`. Permission
 * delegates to the route's own check (no `require_permission` floor), so visibility
 * follows REST. The default item `type` is `custom`, for which the controller
 * requires `title` and `url`. Use `menus` to place the item in a parent menu term.
 *
 * @since 0.3.0
 */
final class CreateMenuItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/create-menu-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menu-items',
				'method'          => 'POST',
				'label'           => __( 'Create Menu Item', 'abilities-catalog' ),
				'description'     => __( 'Creates a classic menu item. For the default "custom" type, both title and url are required. Set "menus" to the parent menu term ID; omitting it creates an orphaned item not attached to any menu.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'      => array(
							'type'        => 'string',
							'description' => __( 'The menu item label. Required for the "custom" type.', 'abilities-catalog' ),
						),
						'url'        => array(
							'type'        => 'string',
							'description' => __( 'The URL the item points to. Required for the "custom" type.', 'abilities-catalog' ),
						),
						'type'       => array(
							'type'        => 'string',
							'enum'        => array( 'taxonomy', 'post_type', 'post_type_archive', 'custom' ),
							'default'     => 'custom',
							'description' => __( 'The family of object the item represents.', 'abilities-catalog' ),
						),
						'object'     => array(
							'type'        => 'string',
							'description' => __( 'The object type, such as "category", "post", or "page". Required together with object_id for the "taxonomy", "post_type", and "post_type_archive" types.', 'abilities-catalog' ),
						),
						'object_id'  => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'The database ID of the linked object (post ID or term ID). Required for the "taxonomy", "post_type", and "post_type_archive" types; core errors if the object does not exist.', 'abilities-catalog' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'The menu item ID of the parent item, or 0 for a top-level item. Find item IDs via og-menus/list-menu-items.', 'abilities-catalog' ),
						),
						'menu_order' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The position of the item within the menu.', 'abilities-catalog' ),
						),
						'menus'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent classic menu term ID this item belongs to. Find menu IDs via og-menus/list-classic-menus. If omitted, core creates an orphaned item not attached to any menu; check the "menus" output field for 0 to detect this.', 'abilities-catalog' ),
						),
						'status'     => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft' ),
							'default'     => 'publish',
							'description' => __( 'The menu item post status. Core honors only "publish" and "draft"; any other value is normalized to "draft".', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'coerceInput' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => __( 'The new menu item ID.', 'abilities-catalog' ),
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
	 * Coerces the integer input fields with `absint()` before dispatch.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()` after
	 * the ability validates input against its schema. It mirrors the old execute()
	 * coercion: the four integer fields pass through `absint()` when present. The
	 * route also sanitizes, so this only normalizes the curated input shape.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with coerced integer fields.
	 */
	public function coerceInput( array $params ): array {
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
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST menu-item body. `title` is un-nested from its `{ rendered: ... }` (or
	 * `{ raw: ... }`) shape; the other fields copy across with a type cast and a safe
	 * default. `$input` and `$response` are part of the callback signature but unused
	 * here — the body carries everything this shape needs.
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
