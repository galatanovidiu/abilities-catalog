<?php
/**
 * Integration tests for the settings/get-permalinks ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetPermalinks;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/get-permalinks is a net-new read of the stored permalink option values.
 * It always returns all three string fields; manage_options is the hard guard.
 */
final class GetPermalinksTest extends TestCase {

	/**
	 * The full, always-present output field set, in order.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'permalink_structure',
		'category_base',
		'tag_base',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/get-permalinks' ) );
	}

	public function test_execute_returns_all_string_fields_in_order(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/get-permalinks' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( self::FIELDS, array_keys( $result ) );

		foreach ( self::FIELDS as $field ) {
			$this->assertIsString( $result[ $field ], "$field must be a string" );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/get-permalinks' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new GetPermalinks();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
