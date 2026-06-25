<?php
/**
 * Integration tests for the og-themes/remove-theme-mod ability.
 *
 * Covers registration, the output-shape contract (name/removed), a happy-path
 * remove with a get_theme_mod read-back, the no-op case (removing an unset mod
 * returns removed=false, not an error), and the edit_theme_options capability gate
 * for logged-out and subscriber callers with a proof the mod survives a denied call.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-themes/remove-theme-mod registration, remove semantics, and the gate.
 */
final class RemoveThemeModTest extends TestCase {

	private const TEST_MOD = 'abilities_catalog_test_mod';

	protected function tearDown(): void {
		remove_theme_mod( self::TEST_MOD );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-themes/remove-theme-mod' ) );
	}

	public function test_output_schema_requires_name_and_removed(): void {
		$schema = wp_get_ability( 'og-themes/remove-theme-mod' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'name', 'removed' ), $schema['required'] );
	}

	public function test_removes_existing_mod_and_reads_back_gone(): void {
		$this->actingAs( 'administrator' );

		set_theme_mod( self::TEST_MOD, 'a-value' );
		$this->assertSame( 'a-value', get_theme_mod( self::TEST_MOD ) );

		$result = wp_get_ability( 'og-themes/remove-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'name', 'removed' ), array_keys( $result ) );
		$this->assertSame( self::TEST_MOD, $result['name'] );
		$this->assertTrue( $result['removed'] );
		$this->assertIsBool( $result['removed'] );

		// Side-effect read-back: the mod is no longer set.
		$this->assertArrayNotHasKey( self::TEST_MOD, (array) get_theme_mods() );
	}

	public function test_removing_unset_mod_is_a_benign_no_op(): void {
		$this->actingAs( 'administrator' );

		$this->assertArrayNotHasKey( self::TEST_MOD, (array) get_theme_mods() );

		$result = wp_get_ability( 'og-themes/remove-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertIsArray( $result );
		$this->assertSame( self::TEST_MOD, $result['name'] );
		$this->assertFalse( $result['removed'] );
	}

	public function test_logged_out_user_is_denied_and_mod_survives(): void {
		set_theme_mod( self::TEST_MOD, 'still-here' );

		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-themes/remove-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The mod must survive the denied call.
		$this->assertSame( 'still-here', get_theme_mod( self::TEST_MOD ) );
	}

	public function test_subscriber_is_denied_and_mod_survives(): void {
		set_theme_mod( self::TEST_MOD, 'still-here' );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-themes/remove-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$this->assertSame( 'still-here', get_theme_mod( self::TEST_MOD ) );
	}
}
