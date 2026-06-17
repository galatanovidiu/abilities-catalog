<?php
/**
 * Integration tests for the menus/update-classic-menu ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises classic menu updates: happy path, assigned-locations output shape,
 * and the capability guard on execute().
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
		$ability = wp_get_ability( 'menus/update-classic-menu' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/update-classic-menu', $ability->get_name() );
	}

	public function test_admin_updates_menu_name(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'menus/update-classic-menu' )->execute(
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

		$result = wp_get_ability( 'menus/update-classic-menu' )->execute(
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

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'menus/update-classic-menu' )->execute(
			array(
				'id'   => $menu_id,
				'name' => 'Denied Menu',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'menus/update-classic-menu' )->execute(
			array(
				'id'   => -37,
				'name' => 'Bad Menu',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
