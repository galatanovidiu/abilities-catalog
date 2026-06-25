<?php
/**
 * Integration tests for the og-menus/list-classic-menus ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-menus/list-classic-menus wraps `GET /wp/v2/menus` and projects each
 * `nav_menu` term into a flat, closed summary row via MenuListShaper.
 * edit_theme_options is the coarse capability guard.
 */
final class ListClassicMenusTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/list-classic-menus' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/list-classic-menus', $ability->get_name() );
	}

	public function test_admin_lists_classic_menus_with_totals(): void {
		$this->actingAs( 'administrator' );
		wp_create_nav_menu( 'Header Menu' );
		wp_create_nav_menu( 'Footer Menu' );

		$result = wp_get_ability( 'og-menus/list-classic-menus' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_rows_are_flat_and_closed(): void {
		$this->actingAs( 'administrator' );
		wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/list-classic-menus' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// items must be a plain list, not a keyed map.
		$this->assertSame( array_keys( $result['items'] ), range( 0, count( $result['items'] ) - 1 ) );

		foreach ( $result['items'] as $row ) {
			// Exactly the declared flat set, in order: no _links, no nested objects,
			// no field core does not emit (e.g. count).
			$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
			$this->assertIsInt( $row['id'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['slug'] );
			$this->assertIsString( $row['description'] );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-menus/list-classic-menus' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
