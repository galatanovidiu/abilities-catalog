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
 * T2 non-destructive write ability: `menus/update-menu-item`.
 *
 * Wraps `POST /wp/v2/menu-items/<id>` via `rest_do_request()` to update a classic
 * menu item (`nav_menu_item` post). The menu-items controller extends the posts
 * controller; the update permission is the object-level `edit_post` capability on
 * the menu item ID, which `map_meta_cap` resolves to `edit_theme_options` for the
 * `nav_menu_item` post type. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class UpdateMenuItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/update-menu-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Menu Item', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing classic menu item by ID. Only the supplied fields change; an empty-string value for a text field is skipped, not cleared.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The menu item ID to update. Find item IDs via menus/list-menu-items.', 'abilities-catalog' ),
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
						'description' => __( 'The parent classic menu term ID this item belongs to. Find menu IDs via menus/list-classic-menus.', 'abilities-catalog' ),
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
			'output_schema'       => array(
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
	 * Permission check mirroring the menu-items (posts) controller update path.
	 *
	 * Checks `edit_post` on the menu item ID; `map_meta_cap` resolves it to
	 * `edit_theme_options` for `nav_menu_item`. The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the menu item.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated item's shaped summary, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/menu-items/' . $id );

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
			'id'         => (int) ( $data['id'] ?? $id ),
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
