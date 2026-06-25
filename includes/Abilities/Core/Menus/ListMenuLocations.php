<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-menus/list-menu-locations`.
 *
 * Lists the navigation menu locations the active theme registers, with each
 * location's description and the classic menu currently assigned to it (if any).
 * This is the companion read for `og-menus/assign-menu-location`: it tells an agent
 * which location slugs exist before assigning a menu. Wraps core
 * `get_registered_nav_menus()` and `get_nav_menu_locations()`.
 *
 * @since 0.5.0
 */
final class ListMenuLocations implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/list-menu-locations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Menu Locations', 'abilities-catalog' ),
			'description'         => __( 'Returns the navigation menu locations registered by the active theme, each with its description and the classic menu assigned to it (if any). Use this to find a location slug before calling og-menus/assign-menu-location.', 'abilities-catalog' ),
			'category'            => 'og-core-menus',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'locations' ),
				'properties'           => array(
					'locations' => array(
						'type'        => 'array',
						'description' => __( 'The theme\'s registered menu locations.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'location', 'description', 'menu_id', 'menu_name' ),
							'properties'           => array(
								'location'    => array(
									'type'        => 'string',
									'description' => __( 'The location slug used when assigning a menu.', 'abilities-catalog' ),
								),
								'description' => array(
									'type'        => 'string',
									'description' => __( 'The human-readable location name from the theme.', 'abilities-catalog' ),
								),
								'menu_id'     => array(
									'type'        => 'integer',
									'description' => __( 'The classic menu term ID assigned to this location, or 0 if none.', 'abilities-catalog' ),
								),
								'menu_name'   => array(
									'type'        => 'string',
									'description' => __( 'The name of the assigned menu, or an empty string if none. An empty string with a non-zero menu_id means the assigned menu no longer exists (stale assignment).', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: managing menu locations requires theme-options access.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return bool True if the current user may read menu locations.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by reading registered locations and their assignments.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return array<string,mixed> The registered menu locations.
	 */
	public function execute( $input = null ): array {
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();

		$locations = array();
		foreach ( $registered as $location => $description ) {
			$menu_id   = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
			$menu_name = '';

			if ( $menu_id > 0 ) {
				$menu_obj  = wp_get_nav_menu_object( $menu_id );
				$menu_name = $menu_obj ? (string) $menu_obj->name : '';
			}

			$locations[] = array(
				'location'    => (string) $location,
				'description' => (string) $description,
				'menu_id'     => $menu_id,
				'menu_name'   => $menu_name,
			);
		}

		return array(
			'locations' => $locations,
		);
	}
}
