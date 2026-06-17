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
 * T2 destructive write ability: `menus/delete-menu-item`.
 *
 * Wraps `DELETE /wp/v2/menu-items/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting a classic menu item (`nav_menu_item` post). Menu items have
 * no Trash: the menu-items controller returns HTTP 501 when `force` is false, so a
 * permanent delete is the only option. The `permission_callback` mirrors the
 * menu-items (posts) controller `delete_item_permissions_check`: object-level
 * `delete_post` on the menu item ID, which `map_meta_cap` resolves to
 * `edit_theme_options` for `nav_menu_item`. This ability never calls
 * `wp_delete_post()` directly; it surfaces the REST route's `WP_Error` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteMenuItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/delete-menu-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Menu Item', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a classic menu item by ID. Menu items have no Trash, so this cannot be undone.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The menu item ID to permanently delete. Find item IDs via menus/list-menu-items.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the menu item was permanently deleted.', 'abilities-catalog' ),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'The deleted menu item ID.', 'abilities-catalog' ),
					),
					'previous_title' => array(
						'type'        => 'string',
						'description' => __( 'The label of the menu item that was deleted.', 'abilities-catalog' ),
					),
					'previous_menus' => array(
						'type'        => 'integer',
						'description' => __( 'The classic menu term ID the deleted item belonged to, or 0 if it was orphaned.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'nav-menus.php',
			),
		);
	}

	/**
	 * Permission check: object-level `delete_post` on the target menu item.
	 *
	 * For `nav_menu_item`, `delete_post` maps to `edit_theme_options` with no
	 * owner-vs-others split, so this coarse, object-independent check is exactly what
	 * core requires — never stricter, never weaker. The object decision (and a missing-id
	 * 404) is left to the wrapped `DELETE /wp/v2/menu-items/<id>` route, so its specific
	 * `rest_post_invalid_id` 404 reaches the caller instead of the generic denial the
	 * Abilities API substitutes for a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage nav menus.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=true` so the menu item is permanently deleted (menu items have
	 * no Trash). Any REST error is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, and a snapshot of the destroyed item, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/menu-items/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data     = rest_get_server()->response_to_data( $response, false );
		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();

		$result = array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => $id,
		);

		if ( isset( $previous['title'] ) ) {
			$title = $previous['title'];
			if ( is_array( $title ) ) {
				$title = $title['rendered'] ?? ( $title['raw'] ?? '' );
			}
			$result['previous_title'] = (string) $title;
		}

		if ( isset( $previous['menus'] ) ) {
			$result['previous_menus'] = (int) $previous['menus'];
		}

		return $result;
	}
}
