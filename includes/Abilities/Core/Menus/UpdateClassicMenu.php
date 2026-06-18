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
 * T2 non-destructive write ability: `menus/update-classic-menu`.
 *
 * Wraps `POST /wp/v2/menus/<id>` via `rest_do_request()` to update a classic menu
 * (`nav_menu` term). The menus controller inherits its update permission from the
 * terms controller, which checks the object-level `edit_term` capability on the
 * term ID; for the `nav_menu` taxonomy this maps to `edit_theme_options`. Optionally
 * reassigns theme locations via the controller's `locations` write field. Write
 * annotations (`readonly:false, destructive:false, idempotent:false`) route the
 * call as POST.
 *
 * @since 0.3.0
 */
final class UpdateClassicMenu implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/update-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Classic Menu', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing classic (nav_menu term) menu by ID. Only the supplied fields change.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The classic menu term ID to update. Use menus/list-classic-menus to discover valid menu IDs.', 'abilities-catalog' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The menu name.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The menu description.', 'abilities-catalog' ),
					),
					'locations'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Theme location slugs to assign this menu to. This replaces the menu\'s entire set of assigned locations: any location omitted here is cleared. Use menus/list-menu-locations to discover valid location slugs.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The resulting menu name.', 'abilities-catalog' ),
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
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The menu's id, name, and assigned locations, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/menus/' . $id );

		// Forward whenever the key is present, including '' so the caller can clear
		// the menu description. Core writes empty strings on update.
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		// Deliberate divergence from CreateClassicMenu (which keeps `! empty`): an
		// update has an existing location set to clear, and the schema documents that
		// `locations` replaces the whole set. Forward [] so the caller can clear all
		// assigned locations. Do not "fix" this back to `! empty`.
		if ( array_key_exists( 'locations', $input ) && is_array( $input['locations'] ) ) {
			$request->set_param( 'locations', array_map( 'sanitize_key', $input['locations'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'        => (int) ( $data['id'] ?? $id ),
			'name'      => (string) ( $data['name'] ?? '' ),
			'locations' => isset( $data['locations'] ) && is_array( $data['locations'] ) ? array_values( $data['locations'] ) : array(),
		);
	}
}
