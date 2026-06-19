<?php
/**
 * Integration tests for the menus/get-classic-menu ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single classic menu read: flat fields out, the empty `meta`
 * case cast to an object, and the capability guard enforced on execute().
 */
final class GetClassicMenuTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_nav_menu( 'ac_primary', 'AC Primary' );
	}

	public function tear_down(): void {
		// Nav-menu registrations are not auto-reset between tests; remove ours.
		unregister_nav_menu( 'ac_primary' );
		remove_theme_mod( 'nav_menu_locations' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'menus/get-classic-menu' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/get-classic-menu', $ability->get_name() );
	}

	public function test_admin_reads_menu_by_id(): void {
		$this->actingAs( 'administrator' );

		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( (int) $menu_id, $result['id'] );
		$this->assertSame( 'Header Menu', $result['name'] );
	}

	public function test_empty_meta_is_returned_as_object(): void {
		$this->actingAs( 'administrator' );

		$menu_id = wp_create_nav_menu( 'No Meta Menu' );

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertIsArray( $result );
		$this->assertIsObject( $result['meta'] );
		$this->assertSame( '{}', wp_json_encode( $result['meta'] ) );
	}

	public function test_count_reflects_menu_item_count(): void {
		$this->actingAs( 'administrator' );

		$menu_id = wp_create_nav_menu( 'Two Item Menu' );
		foreach ( array( 'X', 'Y' ) as $title ) {
			wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'  => $title,
					'menu-item-status' => 'publish',
					'menu-item-type'   => 'custom',
					'menu-item-url'    => 'https://example.com',
				)
			);
		}

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
	}

	public function test_empty_menu_reports_zero_count(): void {
		$this->actingAs( 'administrator' );

		$menu_id = wp_create_nav_menu( 'Empty Count Menu' );

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['count'] );
	}

	public function test_locations_and_auto_add_are_reported(): void {
		$this->actingAs( 'administrator' );

		$menu_id = wp_create_nav_menu( 'Assigned Menu' );
		set_theme_mod( 'nav_menu_locations', array( 'ac_primary' => $menu_id ) );

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertIsArray( $result );
		$this->assertIsArray( $result['locations'] );
		$this->assertContains( 'ac_primary', $result['locations'] );
		$this->assertArrayHasKey( 'auto_add', $result );
		$this->assertIsBool( $result['auto_add'] );

		// An unassigned menu reports an empty locations list.
		$other_id = wp_create_nav_menu( 'Unassigned Menu' );
		$other    = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $other_id ) );

		$this->assertIsArray( $other );
		$this->assertSame( array(), $other['locations'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$menu_id = wp_create_nav_menu( 'Guarded Menu' );

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
