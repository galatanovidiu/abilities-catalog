<?php
/**
 * Integration tests for the og-menus/update-classic-menu ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises classic menu updates: happy path, assigned-locations output shape,
 * and the wrapped route's capability check surfacing through execute().
 */
final class UpdateClassicMenuTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_nav_menu( 'ac_primary', 'AC Primary' );
	}

	public function tear_down(): void {
		unregister_nav_menu( 'ac_primary' );
		remove_theme_mod( 'nav_menu_locations' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/update-classic-menu' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/update-classic-menu', $ability->get_name() );
	}

	public function test_admin_updates_menu_name(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'   => $menu_id,
				'name' => 'Footer Menu',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( (int) $menu_id, $result['id'] );
		$this->assertSame( 'Footer Menu', $result['name'] );
		$this->assertArrayHasKey( 'locations', $result );

		$term = get_term( $menu_id, 'nav_menu' );
		$this->assertNotWPError( $term );
		$this->assertSame( 'Footer Menu', $term->name );
	}

	public function test_update_returns_assigned_locations(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'        => $menu_id,
				'locations' => array( 'ac_primary' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertContains( 'ac_primary', $result['locations'] );

		$locations = get_theme_mod( 'nav_menu_locations' );
		$this->assertSame( (int) $menu_id, (int) $locations['ac_primary'] );
	}

	public function test_empty_locations_array_clears_all_locations(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'        => $menu_id,
				'locations' => array( 'ac_primary' ),
			)
		);
		$assigned = get_nav_menu_locations();
		$this->assertSame( (int) $menu_id, (int) ( $assigned['ac_primary'] ?? 0 ) );

		$result = wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'        => $menu_id,
				'locations' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['locations'] );

		$locations = get_nav_menu_locations();
		$this->assertArrayNotHasKey( 'ac_primary', $locations );
	}

	public function test_subscriber_is_denied(): void {
		// Adapter-backed: the route's own edit_term check (edit_theme_options for the
		// nav_menu taxonomy) runs at dispatch, so execute() surfaces the route's REAL
		// error, not the generic ability_invalid_permissions collapse. This ability sets
		// no require_permission floor, so the route is the sole authority.
		$this->actingAs( 'subscriber' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'   => $menu_id,
				'name' => 'Denied Menu',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'   => -37,
				'name' => 'Bad Menu',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_missing_menu_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// An admin holds edit_theme_options, so a non-existent menu reaches the route and
		// surfaces its real rest_term_invalid 404 through execute() — not the opaque
		// ability_invalid_permissions the old object-level pre-check produced.
		$result = wp_get_ability( 'og-menus/update-classic-menu' )->execute(
			array(
				'id'   => 999999,
				'name' => 'Renamed',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
