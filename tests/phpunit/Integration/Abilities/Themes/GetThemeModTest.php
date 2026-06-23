<?php
/**
 * Integration tests for the themes/get-theme-mod ability.
 *
 * Covers registration, the output-shape contract (name/is_set/value), a happy-path
 * read of a set mod with its value, the unset case (is_set false, value null, NOT
 * the theme default), and the edit_theme_options capability gate for logged-out and
 * subscriber callers.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises themes/get-theme-mod registration, read semantics, and the gate.
 */
final class GetThemeModTest extends TestCase {

	/**
	 * Theme mod name seeded by the happy-path tests.
	 */
	private const TEST_MOD = 'abilities_catalog_test_mod';

	/**
	 * Removes any mod seeded by a test so theme state does not leak between tests.
	 */
	protected function tearDown(): void {
		remove_theme_mod( self::TEST_MOD );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'themes/get-theme-mod' ) );
	}

	public function test_output_schema_requires_name_is_set_and_value(): void {
		$schema = wp_get_ability( 'themes/get-theme-mod' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'name', 'is_set', 'value' ), $schema['required'] );
	}

	public function test_returns_value_for_a_set_mod(): void {
		$this->actingAs( 'administrator' );

		set_theme_mod( self::TEST_MOD, 'sky-blue' );

		$result = wp_get_ability( 'themes/get-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'name', 'is_set', 'value' ), array_keys( $result ) );
		$this->assertSame( self::TEST_MOD, $result['name'] );
		$this->assertTrue( $result['is_set'] );
		$this->assertIsBool( $result['is_set'] );
		$this->assertSame( 'sky-blue', $result['value'] );
	}

	public function test_unset_mod_reports_not_set_with_null_value(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'themes/get-theme-mod' )->execute(
			array( 'name' => 'abilities_catalog_mod_never_set' )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['is_set'] );
		$this->assertNull( $result['value'] );
		$this->assertSame( 'abilities_catalog_mod_never_set', $result['name'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'themes/get-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'themes/get-theme-mod' )->execute(
			array( 'name' => self::TEST_MOD )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
