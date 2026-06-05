<?php
/**
 * Integration tests for the settings/update-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdateMediaSettings;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * settings/update-media writes the Media Settings screen via update_option().
 * None of the keys are REST-registered. manage_options is the hard capability
 * guard; integer dimensions declare minimum 0 and bools are stored as 1/0.
 */
final class UpdateMediaSettingsTest extends TestCase {

	public function test_admin_writes_media_settings(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-media' )->execute(
			array(
				'thumbnail_size_w' => 120,
				'thumbnail_size_h' => 120,
				'thumbnail_crop'   => false,
				'medium_size_w'    => 320,
				'large_size_w'     => 1080,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 120, $result['thumbnail_size_w'] );
		$this->assertSame( 120, $result['thumbnail_size_h'] );
		$this->assertFalse( $result['thumbnail_crop'] );
		$this->assertSame( 320, $result['medium_size_w'] );
		$this->assertSame( 1080, $result['large_size_w'] );

		$this->assertSame( 120, absint( get_option( 'thumbnail_size_w' ) ) );
		$this->assertSame( 320, absint( get_option( 'medium_size_w' ) ) );
		$this->assertSame( 1080, absint( get_option( 'large_size_w' ) ) );
		$this->assertSame( '0', (string) get_option( 'thumbnail_crop' ) );
	}

	public function test_output_shape_contains_all_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'settings/update-media' )->execute( array() );

		$this->assertIsArray( $result );

		$expected = array(
			'thumbnail_size_w',
			'thumbnail_size_h',
			'thumbnail_crop',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
			'uploads_use_yearmonth_folders',
		);

		foreach ( $expected as $field ) {
			$this->assertArrayHasKey( $field, $result );
		}
	}

	public function test_integer_inputs_declare_minimum_zero(): void {
		$schema = ( new UpdateMediaSettings() )->args()['input_schema']['properties'];

		$fields = array(
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
		);

		foreach ( $fields as $field ) {
			$this->assertSame( 0, $schema[ $field ]['minimum'], $field . ' must declare minimum 0' );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'settings/update-media' )->execute(
			array( 'thumbnail_size_w' => 120 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
