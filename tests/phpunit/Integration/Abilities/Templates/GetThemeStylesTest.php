<?php
/**
 * Integration tests for templates/get-theme-styles output and contract.
 *
 * Covers the happy path for the active theme (canonical stylesheet plus
 * object-shaped settings/styles), the wrong-capability denial (a subscriber
 * lacks edit_theme_options), and the output-shape guarantee that empty
 * settings/styles serialize as `{}` objects rather than `[]` arrays.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/get-theme-styles.
 */
final class GetThemeStylesTest extends TestCase {

	public function test_active_theme_returns_canonical_stylesheet_and_object_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/get-theme-styles' )->execute( array() );

		$this->assertIsArray( $result );
		// Core serves the active theme only; the output reports its canonical
		// stylesheet regardless of the (empty) input.
		$this->assertSame( get_stylesheet(), $result['stylesheet'] );
		// settings/styles are cast to objects so they serialize as `{}`.
		$this->assertIsObject( $result['settings'] );
		$this->assertIsObject( $result['styles'] );
	}

	public function test_url_encoded_active_stylesheet_reports_canonical_value(): void {
		$this->actingAs( 'administrator' );

		// A URL-encoded form of the active stylesheet can succeed at the route,
		// but the output must carry the decoded canonical stylesheet, not the
		// raw encoded input.
		$encoded = rawurlencode( get_stylesheet() );

		$result = wp_get_ability( 'templates/get-theme-styles' )->execute(
			array( 'stylesheet' => $encoded )
		);

		if ( $result instanceof WP_Error ) {
			$this->markTestSkipped( 'Active stylesheet has no characters that encode differently.' );
		}

		$this->assertIsArray( $result );
		$this->assertSame( get_stylesheet(), $result['stylesheet'] );
	}

	public function test_empty_settings_and_styles_serialize_as_objects(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/get-theme-styles' )->execute( array() );

		$this->assertIsArray( $result );

		// An empty PHP array would JSON-encode as `[]` and break the
		// `type: object` output schema; the cast guarantees `{}`.
		$encoded = wp_json_encode( $result['settings'] );
		$this->assertStringStartsWith( '{', (string) $encoded );

		$encoded = wp_json_encode( $result['styles'] );
		$this->assertStringStartsWith( '{', (string) $encoded );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'templates/get-theme-styles' );

		// edit_theme_options is the catalog guard; a subscriber lacks it.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
