<?php
/**
 * Integration tests for the og-menus/list-menu-locations ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the no-input read ability: registered locations out, with the
 * assigned menu resolved, and the capability guard enforced on execute().
 */
final class ListMenuLocationsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_nav_menu( 'ac_primary', 'AC Primary' );
	}

	public function tear_down(): void {
		unregister_nav_menu( 'ac_primary' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/list-menu-locations' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/list-menu-locations', $ability->get_name() );
	}

	public function test_admin_sees_registered_location(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/list-menu-locations' )->execute();

		$this->assertIsArray( $result );
		$slugs = wp_list_pluck( $result['locations'], 'location' );
		$this->assertContains( 'ac_primary', $slugs );
	}

	public function test_assigned_menu_is_resolved(): void {
		$this->actingAs( 'administrator' );

		$menu_id = wp_create_nav_menu( 'Header Menu' );
		set_theme_mod( 'nav_menu_locations', array( 'ac_primary' => $menu_id ) );

		$result   = wp_get_ability( 'og-menus/list-menu-locations' )->execute();
		$location = null;
		foreach ( $result['locations'] as $entry ) {
			if ( 'ac_primary' === $entry['location'] ) {
				$location = $entry;
				break;
			}
		}

		$this->assertNotNull( $location );
		$this->assertSame( (int) $menu_id, $location['menu_id'] );
		$this->assertSame( 'Header Menu', $location['menu_name'] );
	}

	public function test_unassigned_location_reports_zero_id_and_empty_name(): void {
		$this->actingAs( 'administrator' );

		remove_theme_mod( 'nav_menu_locations' );

		$result   = wp_get_ability( 'og-menus/list-menu-locations' )->execute();
		$location = null;
		foreach ( $result['locations'] as $entry ) {
			if ( 'ac_primary' === $entry['location'] ) {
				$location = $entry;
				break;
			}
		}

		$this->assertNotNull( $location );
		$this->assertArrayHasKey( 'menu_name', $location );
		$this->assertSame( 0, $location['menu_id'] );
		$this->assertSame( '', $location['menu_name'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-menus/list-menu-locations' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
