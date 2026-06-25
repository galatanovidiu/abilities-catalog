<?php
/**
 * Integration tests for the og-menus/create-classic-menu ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises classic menu creation: happy path, locations output shape, explicit
 * empty-name forwarding to core, and the capability guard on execute().
 */
final class CreateClassicMenuTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_nav_menu( 'ac_primary', 'AC Primary' );
	}

	public function tear_down(): void {
		unregister_nav_menu( 'ac_primary' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/create-classic-menu' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/create-classic-menu', $ability->get_name() );
	}

	public function test_admin_creates_menu(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/create-classic-menu' )->execute(
			array( 'name' => 'Header Menu' )
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Header Menu', $result['name'] );
		$this->assertSame( array(), $result['locations'] );

		$term = get_term( $result['id'], 'nav_menu' );
		$this->assertNotWPError( $term );
		$this->assertSame( 'Header Menu', $term->name );
	}

	public function test_created_menu_returns_assigned_locations(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/create-classic-menu' )->execute(
			array(
				'name'      => 'Located Menu',
				'locations' => array( 'ac_primary' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertContains( 'ac_primary', $result['locations'] );
	}

	public function test_explicit_empty_name_surfaces_core_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-menus/create-classic-menu' )->execute(
			array( 'name' => '' )
		);

		// Name is forwarded as an explicit empty string; core rejects it on the
		// empty-name path, not as a missing required param.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'rest_missing_callback_param', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-menus/create-classic-menu' )->execute(
			array( 'name' => 'Denied Menu' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
