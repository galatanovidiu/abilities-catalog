<?php
/**
 * Integration tests for the themes/set-theme-mod ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes\SetThemeMod;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * themes/set-theme-mod wraps core `set_theme_mod()` (which checks no capability),
 * repeats the `edit_theme_options` guard at the top of execute(), and reads the
 * value back to report `set`.
 */
final class SetThemeModTest extends TestCase {

	/**
	 * The theme mod name seeded and cleaned up by these tests.
	 *
	 * @var string
	 */
	private const TEST_MOD = 'abilities_catalog_test_mod';

	protected function tearDown(): void {
		remove_theme_mod( self::TEST_MOD );

		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'themes/set-theme-mod' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'themes/set-theme-mod', $ability->get_name() );
	}

	public function test_admin_sets_a_string_theme_mod(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'themes/set-theme-mod' )->execute(
			array(
				'name'  => self::TEST_MOD,
				'value' => 'midnight',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'name', 'value', 'set' ), array_keys( $result ) );
		$this->assertSame( self::TEST_MOD, $result['name'] );
		$this->assertTrue( $result['set'] );
		$this->assertSame( 'midnight', $result['value'] );

		// Side-effect read-back via core: the mod is actually stored.
		$this->assertSame( 'midnight', get_theme_mod( self::TEST_MOD ) );
	}

	public function test_array_value_round_trips(): void {
		$this->actingAs( 'administrator' );

		$value = array(
			'color' => '#fff',
			'size'  => 12,
		);

		$result = wp_get_ability( 'themes/set-theme-mod' )->execute(
			array(
				'name'  => self::TEST_MOD,
				'value' => $value,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['set'] );
		$this->assertSame( $value, $result['value'] );
		$this->assertSame( $value, get_theme_mod( self::TEST_MOD ) );
	}

	public function test_logged_out_is_denied_and_does_not_set(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'themes/set-theme-mod' )->execute(
			array(
				'name'  => self::TEST_MOD,
				'value' => 'should-not-store',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The write never happened.
		$this->assertFalse( get_theme_mod( self::TEST_MOD ) );
	}

	public function test_subscriber_is_denied_and_does_not_set(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'themes/set-theme-mod' )->execute(
			array(
				'name'  => self::TEST_MOD,
				'value' => 'should-not-store',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The write never happened.
		$this->assertFalse( get_theme_mod( self::TEST_MOD ) );
	}

	public function test_permission_guard_checks_theme_capability(): void {
		$ability = new SetThemeMod();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission( array() ) );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission( array() ) );
	}
}
