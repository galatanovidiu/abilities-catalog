<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
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
						'description' => __( 'The classic menu term ID to update.', 'abilities-catalog' ),
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
						'description' => __( 'Theme location slugs to assign this menu to.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The resulting menu name.', 'abilities-catalog' ),
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
	 * Checks `edit_term` on the term ID; for `nav_menu` it maps to
	 * `edit_theme_options`. The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the classic menu.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_term', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The menu's id and name, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/menus/' . $id );

		foreach ( array( 'name', 'description' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		if ( ! empty( $input['locations'] ) && is_array( $input['locations'] ) ) {
			$request->set_param( 'locations', array_map( 'sanitize_key', $input['locations'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'   => (int) ( $data['id'] ?? $id ),
			'name' => (string) ( $data['name'] ?? '' ),
		);
	}
}
