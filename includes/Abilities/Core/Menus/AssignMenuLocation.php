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
 * T2 non-destructive write ability: `menus/assign-menu-location`.
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
		return 'menus/assign-menu-location';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Assign Menu Location', 'abilities-catalog' ),
			'description'         => __( 'Assigns a classic menu to a registered theme location.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'menu_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The classic menu term ID to assign.', 'abilities-catalog' ),
					),
					'location' => array(
						'type'        => 'string',
						'description' => __( 'The registered theme location slug to assign the menu to.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'menu_id', 'location' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
					),
					'locations' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The theme locations now assigned to the menu.', 'abilities-catalog' ),
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
	 * Checks `edit_term` on the menu term ID; for `nav_menu` it maps to
	 * `edit_theme_options`. The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may assign the menu location.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['menu_id'] ) ? absint( $input['menu_id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_term', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * Sends the single location as the controller's `locations` array field. The
	 * controller validates the slug against `get_registered_nav_menus()` and
	 * returns an error for an unregistered location.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The menu's id and assigned locations, or the REST error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$id       = absint( $input['menu_id'] );
		$location = sanitize_key( (string) ( $input['location'] ?? '' ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/menus/' . $id );
		$request->set_param( 'locations', array( $location ) );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'        => (int) ( $data['id'] ?? $id ),
			'locations' => isset( $data['locations'] ) && is_array( $data['locations'] ) ? array_values( $data['locations'] ) : array(),
		);
	}
}
