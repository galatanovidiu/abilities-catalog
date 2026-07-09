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
 * T2 destructive write ability: `og-menus/delete-menu-item`.
 *
 * Wraps `DELETE /wp/v2/menu-items/<id>` via the Abilities REST Adapter, permanently
 * deleting a classic menu item (`nav_menu_item` post). Menu items have no Trash: the
 * menu-items controller returns HTTP 501 when `force` is false, so {@see injectForce()}
 * pins `force=true` (the caller never passes it, and it is kept out of the input schema).
 * The input is OVERRIDDEN to just the required `id`; the output is OVERRIDDEN to the
 * catalog's flat field set through {@see shapeOutput()}.
 *
 * Permission delegates to the wrapped route, whose `delete_item_permissions_check` maps
 * object-level `delete_post` on a `nav_menu_item` to `edit_theme_options`. No
 * `require_permission` floor is set — the route is the authority — so the route's real
 * error (e.g. `rest_post_invalid_id` 404, `rest_cannot_delete` 403) reaches the caller.
 *
 * Destructive: registered, but exposed to the browser only when both the write and
 * destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteMenuItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/delete-menu-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/menu-items/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Menu Item', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a classic menu item by ID. Menu items have no Trash, so this cannot be undone.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The menu item ID to permanently delete. Find item IDs via og-menus/list-menu-items.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectForce' ),
				'output_schema'   => array(
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
	 * Pins `force=true` so the menu item is permanently deleted.
	 *
	 * Wired as the adapter's `input_callback`. Menu items have no Trash, so the
	 * menu-items controller returns HTTP 501 unless `force` is true. The caller never
	 * passes `force` and it is deliberately absent from the input schema; this injects
	 * it post-validation, at dispatch. The `id` path capture passes through unchanged.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `force` forced on.
	 */
	public function injectForce( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the REST delete response to the catalog's snapshot field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete body (`{ deleted, previous: { ... } }`). `id` is taken from the
	 * original ability input (the deleted post's id). `previous_title` un-nests the
	 * `{ rendered | raw }` title; `previous_menus` copies the classic-menu term id.
	 * Both snapshot fields are emitted only when REST returned them. `$response` is
	 * part of the callback signature but unused — the body carries everything needed.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The deleted flag, id, and the destroyed item's snapshot.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data     = is_array( $data ) ? $data : array();
		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();

		$result = array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => absint( $input['id'] ?? 0 ),
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
