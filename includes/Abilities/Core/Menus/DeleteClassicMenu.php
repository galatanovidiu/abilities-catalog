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
 * T2 destructive write ability: `og-menus/delete-classic-menu`.
 *
 * Wraps `DELETE /wp/v2/menus/<id>` via the Abilities REST Adapter, permanently
 * deleting a whole classic menu (a `nav_menu` term) and all of its menu items.
 * Classic menus have no Trash: the menus controller returns HTTP 501 when `force`
 * is false, so {@see injectForce()} pins `force=true` before dispatch. This
 * deletes the entire menu, not a single item — use `og-menus/delete-menu-item`
 * for one item.
 *
 * The input is OVERRIDDEN to the single `id` path capture (the caller never
 * passes `force`); the output is OVERRIDDEN to the catalog's flat snapshot via
 * {@see shapeOutput()}, drawn from the force-delete response body
 * (`deleted` + `previous{ name, slug, locations }`). Permission delegates to the
 * route's own check — the terms controller's `delete_item_permissions_check`,
 * which `map_meta_cap` resolves to `edit_theme_options` for `nav_menu`. No
 * `require_permission` floor is set: the route is the authority. Capability is
 * the hard guard. Destructive: exposed to the browser only when both the write
 * and destructive adapter settings are on.
 *
 * @since 0.5.0
 */
final class DeleteClassicMenu implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/delete-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menus/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Classic Menu', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes an entire classic menu (a nav_menu term) and all of its items by menu ID. Classic menus have no Trash, so this cannot be undone. Also clears the menu from any theme locations it was assigned to. Deletes the whole menu, not a single item.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'The classic menu (nav_menu term) ID to permanently delete. Discover it with og-menus/list-classic-menus or og-menus/get-classic-menu.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'           => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the menu was permanently deleted.', 'abilities-catalog' ),
						),
						'id'                => array(
							'type'        => 'integer',
							'description' => __( 'The deleted menu ID.', 'abilities-catalog' ),
						),
						'name'              => array(
							'type'        => 'string',
							'description' => __( 'The name of the menu that was deleted.', 'abilities-catalog' ),
						),
						'slug'              => array(
							'type'        => 'string',
							'description' => __( 'The slug of the menu that was deleted.', 'abilities-catalog' ),
						),
						'removed_locations' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Theme location slugs the deleted menu was cleared from.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectForce' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'nav-menus.php',
				),
			)
		);
	}

	/**
	 * Pins `force=true` before dispatch.
	 *
	 * Classic menus have no Trash — the menus controller returns HTTP 501 unless
	 * the delete is forced. The caller never passes `force` (it is not in the
	 * input schema); this fixed param is injected here. `id` passes through
	 * unchanged as the route's path capture.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `force` forced on.
	 */
	public function injectForce( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the REST force-delete body to the catalog's snapshot shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over
	 * the force-delete response body. The body carries `deleted` plus a
	 * `previous` object holding the destroyed menu's `name`, `slug`, and
	 * `locations` — everything this shape needs, with no pre-state required. `id`
	 * is taken from the original ability input. `$response` is part of the
	 * callback signature but unused here.
	 *
	 * @param mixed               $data     The REST force-delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The deleted flag, id, and a snapshot of the destroyed menu.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();

		$result = array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => (int) ( $input['id'] ?? 0 ),
		);

		if ( isset( $previous['name'] ) ) {
			$result['name'] = (string) $previous['name'];
		}

		if ( isset( $previous['slug'] ) ) {
			$result['slug'] = (string) $previous['slug'];
		}

		if ( isset( $previous['locations'] ) && is_array( $previous['locations'] ) ) {
			$result['removed_locations'] = array_values( $previous['locations'] );
		}

		return $result;
	}
}
