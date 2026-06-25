<?php
/**
 * Integration tests for the og-fonts/list-font-collections ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the read: registration, capability guard, output shape, and that
 * the bundled "google-fonts" collection is returned with pagination totals.
 */
final class ListFontCollectionsTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'slug',
		'name',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-fonts/list-font-collections' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-fonts/list-font-collections', $ability->get_name() );
	}

	public function test_admin_lists_collections_with_totals(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-fonts/list-font-collections' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertIsInt( $result['total'] );
		$this->assertIsInt( $result['total_pages'] );

		// The bundled "google-fonts" collection is registered by default.
		$slugs = array_column( $result['items'], 'slug' );
		$this->assertContains( 'google-fonts', $slugs );
	}

	public function test_rows_are_flat_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-fonts/list-font-collections' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// items must be a plain list, not a keyed map.
		$this->assertSame( array_keys( $result['items'] ), range( 0, count( $result['items'] ) - 1 ) );

		foreach ( $result['items'] as $row ) {
			// Exactly the declared flat set, in order: no font_families catalog, no categories, no _links.
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertArrayNotHasKey( 'font_families', $row );
			$this->assertArrayNotHasKey( 'categories', $row );
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['name'] );
		}
	}

	public function test_per_page_limits_returned_items(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-fonts/list-font-collections' )->execute(
			array(
				'page'     => 1,
				'per_page' => 1,
			)
		);

		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 1, count( $result['items'] ) );
	}

	public function test_non_admin_is_denied(): void {
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'og-fonts/list-font-collections' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
