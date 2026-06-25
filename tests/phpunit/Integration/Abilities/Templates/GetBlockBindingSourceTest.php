<?php
/**
 * Integration tests for og-templates/get-block-binding-source output and contract.
 *
 * Covers registration, the happy path (reads the core `core/pattern-overrides`
 * source and projects its name/label/uses_context), the unknown-name error
 * (a specific 404, not a permission collapse), and the wrong-capability /
 * logged-out denials.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/get-block-binding-source.
 */
final class GetBlockBindingSourceTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-templates/get-block-binding-source' ) );
	}

	public function test_returns_core_pattern_overrides_source(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/get-block-binding-source' )->execute(
			array( 'name' => 'core/pattern-overrides' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'core/pattern-overrides', $result['name'] );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertIsString( $result['label'] );
		$this->assertNotSame( '', $result['label'] );
		$this->assertArrayHasKey( 'uses_context', $result );
		$this->assertIsArray( $result['uses_context'] );
		$this->assertContains( 'pattern/overrides', $result['uses_context'] );
	}

	public function test_output_exposes_only_declared_flat_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/get-block-binding-source' )->execute(
			array( 'name' => 'core/pattern-overrides' )
		);

		$this->assertIsArray( $result );
		$expected = array( 'name', 'label', 'uses_context' );
		$this->assertSame( $expected, array_keys( $result ) );
	}

	public function test_unknown_name_returns_specific_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/get-block-binding-source' )->execute(
			array( 'name' => 'acme/nope' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_binding_source_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// A missing source must surface as not-found, never collapse to a permission denial.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-templates/get-block-binding-source' )->execute(
			array( 'name' => 'core/pattern-overrides' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-templates/get-block-binding-source' )->execute(
			array( 'name' => 'core/pattern-overrides' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
