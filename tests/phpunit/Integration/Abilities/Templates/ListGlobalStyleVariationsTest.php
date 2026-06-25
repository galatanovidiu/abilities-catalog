<?php
/**
 * Integration tests for og-templates/list-global-style-variations output and contract.
 *
 * Covers the happy path for the active theme (canonical stylesheet plus an
 * array of items), the output-shape guarantee that each item's settings/styles
 * serialize as `{}` objects rather than `[]` arrays, and the wrong-capability
 * denial (a subscriber lacks edit_theme_options).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/list-global-style-variations.
 */
final class ListGlobalStyleVariationsTest extends TestCase {

	public function test_active_theme_returns_canonical_stylesheet_and_items_array(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-global-style-variations' )->execute( array() );

		$this->assertIsArray( $result );
		// Core serves the active theme only; the output reports its stylesheet.
		$this->assertSame( get_stylesheet(), $result['stylesheet'] );
		$this->assertIsArray( $result['items'] );
	}

	public function test_each_item_settings_and_styles_serialize_as_objects(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-global-style-variations' )->execute( array() );

		$this->assertIsArray( $result );

		if ( array() === $result['items'] ) {
			$this->markTestSkipped( 'Active theme ships no style variations.' );
		}

		foreach ( $result['items'] as $item ) {
			$this->assertIsObject( $item['settings'] );
			$this->assertIsObject( $item['styles'] );

			// An empty PHP array would JSON-encode as `[]` and break the
			// `type: object` item schema; the cast guarantees `{}`.
			$this->assertStringStartsWith( '{', (string) wp_json_encode( $item['settings'] ) );
			$this->assertStringStartsWith( '{', (string) wp_json_encode( $item['styles'] ) );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-templates/list-global-style-variations' );

		// edit_theme_options is the catalog guard; a subscriber lacks it.
		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
