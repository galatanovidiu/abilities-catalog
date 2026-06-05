<?php
/**
 * Integration tests for the settings/get-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\GetMediaSettings;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/get-media is a net-new read of the media option values. It always
 * returns all eight fields; manage_options is the hard capability guard.
 */
final class GetMediaSettingsTest extends TestCase {

	/**
	 * The full, always-present output field set.
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'uploads_use_yearmonth_folders',
	);

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'settings/get-media' ) );
	}

	public function test_execute_returns_exact_shape_and_types(): void {
		$this->actingAs( 'administrator' );

		update_option( 'thumbnail_size_w', 150 );
		update_option( 'thumbnail_size_h', 150 );
		update_option( 'thumbnail_crop', '1' );
		update_option( 'medium_size_w', 300 );
		update_option( 'medium_size_h', 300 );
		update_option( 'large_size_w', 1024 );
		update_option( 'large_size_h', 1024 );
		update_option( 'uploads_use_yearmonth_folders', '1' );

		$result = wp_get_ability( 'settings/get-media' )->execute();

		$this->assertIsArray( $result );
		// All eight fields are present, in order, with no extras.
		$this->assertSame( self::FIELDS, array_keys( $result ) );

		// Size fields are integers.
		foreach (
			array(
				'thumbnail_size_w',
				'thumbnail_size_h',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
			) as $field
		) {
			$this->assertIsInt( $result[ $field ], "$field must be an integer" );
		}

		// Flag fields are booleans.
		$this->assertIsBool( $result['thumbnail_crop'] );
		$this->assertIsBool( $result['uploads_use_yearmonth_folders'] );

		// Values reflect the stored options.
		$this->assertSame( 150, $result['thumbnail_size_w'] );
		$this->assertSame( 150, $result['thumbnail_size_h'] );
		$this->assertTrue( $result['thumbnail_crop'] );
		$this->assertSame( 300, $result['medium_size_w'] );
		$this->assertSame( 300, $result['medium_size_h'] );
		$this->assertSame( 1024, $result['large_size_w'] );
		$this->assertSame( 1024, $result['large_size_h'] );
		$this->assertTrue( $result['uploads_use_yearmonth_folders'] );
	}

	public function test_disabled_flags_report_false(): void {
		$this->actingAs( 'administrator' );

		update_option( 'thumbnail_crop', '0' );
		update_option( 'uploads_use_yearmonth_folders', '0' );

		$result = wp_get_ability( 'settings/get-media' )->execute();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['thumbnail_crop'] );
		$this->assertFalse( $result['uploads_use_yearmonth_folders'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/get-media' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_manage_options(): void {
		$ability = new GetMediaSettings();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
