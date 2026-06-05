<?php
/**
 * Integration tests for the settings/get-general ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetGeneral;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/get-general is a net-new read of the General Settings screen values.
 * It always returns all 11 fields; manage_options is the hard capability guard.
 */
final class GetGeneralTest extends TestCase {

	/**
	 * The full, always-present output field set.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'title',
		'description',
		'url',
		'wpurl',
		'admin_email',
		'timezone',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'language',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/get-general' ) );
	}

	public function test_execute_returns_all_fields_typed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/get-general' )->execute();

		$this->assertIsArray( $result );
		// All 11 fields are always present.
		$this->assertSame( self::FIELDS, array_keys( $result ) );

		// String fields.
		foreach (
			array(
				'title',
				'description',
				'url',
				'wpurl',
				'admin_email',
				'timezone',
				'gmt_offset',
				'date_format',
				'time_format',
				'language',
			) as $field
		) {
			$this->assertIsString( $result[ $field ], "$field must be a string" );
		}

		// start_of_week is an integer.
		$this->assertIsInt( $result['start_of_week'] );

		// Effective URLs and resolved locale match core helpers.
		$this->assertSame( (string) home_url(), $result['url'] );
		$this->assertSame( (string) site_url(), $result['wpurl'] );
		$this->assertSame( get_locale(), $result['language'] );
	}

	public function test_gmt_offset_reflects_named_timezone(): void {
		$this->actingAs( 'administrator' );

		// With a named timezone set, gmt_offset returns the computed effective
		// offset for that zone, not a stored manual value.
		update_option( 'timezone_string', 'Europe/Berlin' );
		update_option( 'gmt_offset', '' );

		$result = wp_get_ability( 'settings/get-general' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( 'Europe/Berlin', $result['timezone'] );
		// Berlin is UTC+1 (winter) or UTC+2 (summer); never empty here.
		$this->assertNotSame( '', $result['gmt_offset'] );
		$this->assertContains( $result['gmt_offset'], array( '1', '2' ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/get-general' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new GetGeneral();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
