<?php
/**
 * Integration tests for the og-menus/assign-menu-location ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the T2 write ability: assigns a classic menu to a theme location,
 * with the capability guard, missing-object and invalid-location errors, and
 * the flat output shape.
 */
final class AssignMenuLocationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_nav_menu( 'ac_primary', 'AC Primary' );
		register_nav_menu( 'ac_secondary', 'AC Secondary' );
	}

	public function tear_down(): void {
		unregister_nav_menu( 'ac_primary' );
		unregister_nav_menu( 'ac_secondary' );
		remove_theme_mod( 'nav_menu_locations' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/assign-menu-location' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/assign-menu-location', $ability->get_name() );
	}

	public function test_admin_assigns_menu_to_location(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => $menu_id,
				'location' => 'ac_primary',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( (int) $menu_id, $result['id'] );
		$this->assertContains( 'ac_primary', $result['locations'] );

		$locations = get_theme_mod( 'nav_menu_locations' );
		$this->assertSame( (int) $menu_id, (int) $locations['ac_primary'] );
	}

	public function test_previous_locations_reports_prior_assignment(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );
		set_theme_mod( 'nav_menu_locations', array( 'ac_secondary' => $menu_id ) );

		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => $menu_id,
				'location' => 'ac_primary',
			)
		);

		$this->assertIsArray( $result );
		$this->assertContains( 'ac_secondary', $result['previous_locations'] );
		$this->assertSame( array( 'ac_primary' ), $result['locations'] );
	}

	public function test_previous_locations_empty_for_unassigned_menu(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => $menu_id,
				'location' => 'ac_primary',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['previous_locations'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => $menu_id,
				'location' => 'ac_primary',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_unregistered_location_is_rejected(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => $menu_id,
				'location' => 'ac_not_registered',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_negative_menu_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => -37,
				'location' => 'ac_primary',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_missing_menu_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// An admin holds edit_theme_options (the coarse guard), so a non-existent menu
		// reaches the route and surfaces its specific 404 instead of the opaque
		// ability_invalid_permissions the object-level pre-check produced.
		$result = wp_get_ability( 'og-menus/assign-menu-location' )->execute(
			array(
				'menu_id'  => 999999,
				'location' => 'ac_primary',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
