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

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$menu_id = wp_create_nav_menu( 'Guarded Menu' );

		$result = wp_get_ability( 'menus/get-classic-menu' )->execute( array( 'id' => $menu_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
