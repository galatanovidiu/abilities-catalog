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
 * T2 non-destructive write ability: `og-menus/assign-menu-location`.
 *
 * Assigns a classic menu (`nav_menu` term) to a registered theme location by
 * wrapping `POST /wp/v2/menus/<id>` via `rest_do_request()` with the controller's
 * `locations` write field. The menus controller exposes `locations` as a writable
 * array of strings and persists it through `set_theme_mod( 'nav_menu_locations', ... )`.
 * The update permission mirrors the terms controller: an object-level `edit_term`
 * check on the menu ID, which maps to `edit_theme_options` for `nav_menu`. Write
 * annotations (`readonly:false, destructive:false, idempotent:false`) route the
 * call as POST.
 *
 * @since 0.3.0
 */
final class AssignMenuLocation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/assign-menu-location';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Assign Menu Location', 'abilities-catalog' ),
			'description'         => __( 'Assigns a classic menu to a registered theme location. This replaces any menu previously assigned to that location, and clears the menu from any other locations it was assigned to.', 'abilities-catalog' ),
			'category'            => 'og-core-menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'menu_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The classic menu term ID to assign. Use og-menus/list-classic-menus to discover valid menu IDs.', 'abilities-catalog' ),
					),
					'location' => array(
						'type'        => 'string',
						'description' => __( 'The registered theme location slug to assign the menu to. Use og-menus/list-menu-locations to discover valid location slugs.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'menu_id', 'location' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'                 => array(
						'type'        => 'integer',
						'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
					),
					'locations'          => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The theme locations now assigned to the menu.', 'abilities-catalog' ),
					),
					'previous_locations' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Theme location slugs this menu held before the call. Empty if it held none. Any menu previously at the target location was displaced.', 'abilities-catalog' ),
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
	 * Permission check mirroring the terms controller update path, object-aware.
	 *
	 * For `nav_menu`, `edit_term` maps to `edit_theme_options` with no owner-vs-others
	 * split, so this coarse, object-independent check is exactly what core requires —
	 * never stricter, never weaker. The object decision (and a missing-id 404) is left
	 * to the wrapped `POST /wp/v2/menus/<id>` route, so its specific `rest_term_invalid`
	 * 404 reaches the caller instead of the generic denial the Abilities API substitutes
	 * for a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage nav menus.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * Sends the single location as the controller's `locations` array field. The
	 * controller validates the slug against `get_registered_nav_menus()` and
	 * returns an error for an unregistered location.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The menu's id, the assigned locations, and the locations it held before the call, or the REST error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$id       = absint( $input['menu_id'] );
		$location = (string) ( $input['location'] ?? '' );

		// Capture the menu's locations before the write so the result reflects pre-write state.
		$previous = array_keys(
			array_filter(
				get_nav_menu_locations(),
				static function ( $assigned_id ) use ( $id ) {
					return (int) $assigned_id === $id;
				}
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/menus/' . $id );
		$request->set_param( 'locations', array( $location ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'                 => (int) ( $data['id'] ?? $id ),
			'locations'          => isset( $data['locations'] ) && is_array( $data['locations'] ) ? array_values( $data['locations'] ) : array(),
			'previous_locations' => $previous,
		);
	}
}
