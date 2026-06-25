<?php
/**
 * Integration tests for the og-settings/update-general ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Settings;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings\UpdateGeneral;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-settings/update-general writes a field-gated subset of the General Settings
 * screen through POST /wp/v2/settings. manage_options is the hard capability
 * guard; the site URL and admin email keys are rejected all-or-nothing; invalid
 * timezone and uninstalled locale input are rejected before any write.
 */
final class UpdateGeneralTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-settings/update-general' ) );
	}

	public function test_admin_writes_general_settings(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-settings/update-general' )->execute(
			array(
				'title'         => 'Catalog Test Site',
				'description'   => 'A tagline',
				'start_of_week' => 3,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Catalog Test Site', $result['title'] );
		$this->assertSame( 'A tagline', $result['description'] );
		$this->assertSame( 3, $result['start_of_week'] );

		// Flat output shape: every documented field is present.
		foreach (
			array(
				'title',
				'description',
				'timezone',
				'date_format',
				'time_format',
				'start_of_week',
				'language',
			) as $field
		) {
			$this->assertArrayHasKey( $field, $result );
		}

		// Persisted to the underlying options.
		$this->assertSame( 'Catalog Test Site', get_option( 'blogname' ) );
		$this->assertSame( 'A tagline', get_option( 'blogdescription' ) );
		$this->assertSame( 3, absint( get_option( 'start_of_week' ) ) );
	}

	public function test_language_output_returns_resolved_locale(): void {
		$this->actingAs( 'administrator' );

		// Empty language selects English; output mirrors GetGeneral's get_locale().
		$result = wp_get_ability( 'og-settings/update-general' )->execute(
			array( 'language' => '' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( get_locale(), $result['language'] );
	}

	public function test_start_of_week_schema_constrains_range(): void {
		$schema = ( new UpdateGeneral() )->args()['input_schema']['properties'];

		$this->assertSame( 0, $schema['start_of_week']['minimum'] );
		$this->assertSame( 6, $schema['start_of_week']['maximum'] );
	}

	public function test_execute_rejects_unknown_timezone(): void {
		$this->actingAs( 'administrator' );

		$before = get_option( 'timezone_string' );

		$result = wp_get_ability( 'og-settings/update-general' )->execute(
			array( 'timezone' => 'Mars/Phobos' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_invalid_timezone', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'timezone_string' ) );
	}

	public function test_execute_rejects_uninstalled_locale(): void {
		$this->actingAs( 'administrator' );

		$before = get_option( 'WPLANG' );

		$result = wp_get_ability( 'og-settings/update-general' )->execute(
			array( 'language' => 'xx_NOT_INSTALLED' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_invalid_language', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'WPLANG' ) );
	}

	public function test_execute_rejects_forbidden_key(): void {
		// Call the ability method directly to reach the defense-in-depth guard,
		// bypassing the schema additionalProperties:false that blocks it upstream.
		$ability = new UpdateGeneral();
		$this->actingAs( 'administrator' );

		$before = get_option( 'admin_email' );

		$result = $ability->execute(
			array( 'admin_email' => 'attacker@example.com' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_field_forbidden', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'admin_email' ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-settings/update-general' )->execute(
			array( 'title' => 'Should Not Apply' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotSame( 'Should Not Apply', get_option( 'blogname' ) );
	}
}
