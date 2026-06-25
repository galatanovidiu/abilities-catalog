<?php
/**
 * Integration tests for the og-settings/get-writing ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetWriting;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-settings/get-writing is a net-new read of the Writing Settings option values.
 * It always returns all 3 fields; manage_options is the hard capability guard.
 */
final class GetWritingTest extends TestCase {

	/**
	 * The full, always-present output field set.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'default_category',
		'default_post_format',
		'use_smilies',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-settings/get-writing' ) );
	}

	public function test_execute_returns_all_fields_typed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-settings/get-writing' )->execute();

		$this->assertIsArray( $result );
		// All 3 fields are always present.
		$this->assertSame( self::FIELDS, array_keys( $result ) );

		// Default category is the term ID integer (via absint).
		$this->assertIsInt( $result['default_category'] );

		// Default post format is a string; falsy stored state normalizes to 'standard'.
		$this->assertIsString( $result['default_post_format'] );
		$this->assertSame( 'standard', $result['default_post_format'] );

		// Smilies conversion is a boolean.
		$this->assertIsBool( $result['use_smilies'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-settings/get-writing' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new GetWriting();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
