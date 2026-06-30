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
 * screen through POST /wp/v2/settings, wrapped through the Abilities REST Adapter.
 * The route's own permission check (manage_options) is the hard guard; the site URL
 * and admin email keys are rejected all-or-nothing in the adapter's input_callback;
 * invalid timezone and uninstalled locale input are rejected before any write.
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
		$schema = ( new UpdateGeneral() )->args()['rest_args']['input_schema']['properties'];

		$this->assertSame( 0, $schema['start_of_week']['minimum'] );
		$this->assertSame( 6, $schema['start_of_week']['maximum'] );
	}

	public function test_execute_rejects_unknown_timezone(): void {
		// timezone is a schema-valid field, so it passes input validation and reaches
		// the adapter's input_callback, where shapeInput() rejects an unrecognized
		// identifier before any dispatch — its real WP_Error surfaces through execute().
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

	public function test_input_callback_rejects_forbidden_key(): void {
		// The forbidden-key guard now lives in the adapter's input_callback (shapeInput).
		// At the registered-ability layer the catalog input_schema's
		// additionalProperties:false rejects a forbidden key first, so this exercises the
		// defense-in-depth guard directly through its new transform seam.
		$result = ( new UpdateGeneral() )->shapeInput(
			array( 'admin_email' => 'attacker@example.com' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_field_forbidden', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		// Permission delegates to the wrapped route (update_items_permissions_check,
		// manage_options). Under the guard-only adapter the route's denial runs at dispatch
		// and surfaces through execute() as the REAL REST error, not the generic collapse.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-settings/update-general' )->execute(
			array( 'title' => 'Should Not Apply' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		// The settings route's permission_callback returns a bare false, so the REST
		// server raises rest_forbidden; 403 because the subscriber is logged in.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'Should Not Apply', get_option( 'blogname' ) );
	}
}
