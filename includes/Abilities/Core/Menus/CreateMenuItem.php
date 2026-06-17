<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `menus/create-menu-item`.
 *
 * Wraps `POST /wp/v2/menu-items` via `rest_do_request()` to create a classic menu
 * item (`nav_menu_item` post). The menu-items controller extends the posts
 * controller; the `nav_menu_item` post type maps `create_posts` / `edit_posts` and
 * `edit_others_posts` to `edit_theme_options`, so the permission check requires
 * `edit_theme_options`. The default item `type` is `custom`, for which the
 * controller requires `title` and `url`. Use `menus` to place the item in a parent
 * menu term. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class CreateMenuItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/create-menu-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Menu Item', 'abilities-catalog' ),
			'description'         => __( 'Creates a classic menu item. For the default "custom" type, both title and url are required. Set "menus" to the parent menu term ID; omitting it creates an orphaned item not attached to any menu.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
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
						'description' => __( 'The menu item ID of the parent item, or 0 for a top-level item. Find item IDs via menus/list-menu-items.', 'abilities-catalog' ),
					),
					'menu_order' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The position of the item within the menu.', 'abilities-catalog' ),
					),
					'menus'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent classic menu term ID this item belongs to. Find menu IDs via menus/list-classic-menus. If omitted, core creates an orphaned item not attached to any menu; check the "menus" output field for 0 to detect this.', 'abilities-catalog' ),
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'nav-menus.php',
			),
		);
	}

	/**
	 * Permission check mirroring the menu-items (posts) controller create path.
	 *
	 * The `nav_menu_item` post type maps `create_posts` / `edit_posts` to
	 * `edit_theme_options`, so managing menu items requires `edit_theme_options`.
	 * The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the menu item.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new item's id and status, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/menu-items' );

		foreach ( array( 'title', 'url', 'type', 'object', 'status' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		foreach ( array( 'object_id', 'parent', 'menu_order', 'menus' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			$request->set_param( $field, absint( $input[ $field ] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

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
