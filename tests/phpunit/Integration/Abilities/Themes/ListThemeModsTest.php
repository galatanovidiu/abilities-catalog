<?php
/**
 * Integration tests for the og-themes/list-theme-mods ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes\ListThemeMods;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-themes/list-theme-mods wraps core `get_theme_mods()` and returns the active
 * theme's Customizer modifications as a flat name-to-value map. edit_theme_options
 * is the capability guard.
 */
final class ListThemeModsTest extends TestCase {

	/**
	 * A test mod name seeded and removed by these tests.
	 *
	 * @var string
	 */
	private const TEST_MOD = 'abilities_catalog_test_mod';

	/**
	 * Removes the seeded test mod after each test.
	 */
	public function tear_down(): void {
		remove_theme_mod( self::TEST_MOD );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-themes/list-theme-mods' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-themes/list-theme-mods', $ability->get_name() );
	}

	public function test_admin_lists_theme_mods_including_a_seeded_one(): void {
		$this->actingAs( 'administrator' );

		set_theme_mod( self::TEST_MOD, 'x' );

		$result = wp_get_ability( 'og-themes/list-theme-mods' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'theme', 'mods', 'total' ), array_keys( $result ) );

		$this->assertSame( get_stylesheet(), $result['theme'] );
		$this->assertIsInt( $result['total'] );

		// mods is cast to an object; the seeded mod is present with its value.
		$this->assertIsObject( $result['mods'] );
		$mods = (array) $result['mods'];
		$this->assertArrayHasKey( self::TEST_MOD, $mods );
		$this->assertSame( 'x', $mods[ self::TEST_MOD ] );
		$this->assertSame( count( $mods ), $result['total'] );
	}

	public function test_empty_map_casts_to_object(): void {
		$this->actingAs( 'administrator' );

		// Ensure no seeded mod is present for this assertion.
		remove_theme_mod( self::TEST_MOD );

		$result = wp_get_ability( 'og-themes/list-theme-mods' )->execute();

		$this->assertIsArray( $result );
		// mods is always a JSON object, never a bare array, so an empty map
		// serializes as {} not [].
		$this->assertIsObject( $result['mods'] );
		$this->assertArrayNotHasKey( self::TEST_MOD, (array) $result['mods'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-themes/list-theme-mods' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-themes/list-theme-mods' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_theme_capabilities(): void {
		$ability = new ListThemeMods();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
