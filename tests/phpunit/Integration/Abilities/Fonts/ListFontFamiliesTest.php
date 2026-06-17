<?php
/**
 * Integration tests for the fonts/list-font-families ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * fonts/list-font-families wraps `GET /wp/v2/font-families` and projects each
 * `wp_font_family` post into a flat, closed summary row via FontListShaper: the
 * descriptive fields are flattened out of font_family_settings and the faces are
 * reduced to a count. edit_theme_options is the coarse capability guard.
 */
final class ListFontFamiliesTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'id',
		'name',
		'slug',
		'font_family',
		'theme_json_version',
		'font_faces_count',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'fonts/list-font-families' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'fonts/list-font-families', $ability->get_name() );
	}

	public function test_admin_lists_families_with_totals(): void {
		$this->actingAs( 'administrator' );
		$this->createFontFamily(
			array(
				'name'       => 'Catalog Sans',
				'slug'       => 'catalog-sans',
				'fontFamily' => 'Catalog Sans, sans-serif',
			)
		);

		$result = wp_get_ability( 'fonts/list-font-families' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
	}

	public function test_rows_are_flat_and_closed_and_flatten_settings(): void {
		$this->actingAs( 'administrator' );
		$family_id = $this->createFontFamily(
			array(
				'name'       => 'Catalog Sans',
				'slug'       => 'catalog-sans',
				'fontFamily' => 'Catalog Sans, sans-serif',
			)
		);
		$this->createFontFace( $family_id );

		$result = wp_get_ability( 'fonts/list-font-families' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// items must be a plain list, not a keyed map.
		$this->assertSame( array_keys( $result['items'] ), range( 0, count( $result['items'] ) - 1 ) );

		$row = $result['items'][0];

		// Exactly the declared flat set, in order: no nested font_family_settings, no _links.
		$this->assertSame( self::ROW_KEYS, array_keys( $row ) );
		$this->assertArrayNotHasKey( 'font_family_settings', $row );
		$this->assertSame( $family_id, $row['id'] );
		$this->assertSame( 'Catalog Sans', $row['name'] );
		$this->assertSame( 'catalog-sans', $row['slug'] );
		$this->assertSame( 'Catalog Sans, sans-serif', $row['font_family'] );
		$this->assertSame( 1, $row['font_faces_count'] );
	}

	public function test_non_admin_is_denied(): void {
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'fonts/list-font-families' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Creates a `wp_font_family` post with the given settings.
	 *
	 * @param array<string,string> $settings Font family settings (name, slug, fontFamily).
	 * @return int The new font family post ID.
	 */
	private function createFontFamily( array $settings ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_font_family',
				'post_status'  => 'publish',
				'post_title'   => $settings['name'] ?? 'Test Family',
				'post_name'    => $settings['slug'] ?? 'test-family',
				'post_content' => (string) wp_json_encode( $settings ),
			)
		);
	}

	/**
	 * Creates a `wp_font_face` child of the given family.
	 *
	 * @param int $family_id Parent font family ID.
	 * @return int The new font face post ID.
	 */
	private function createFontFace( int $family_id ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'wp_font_face',
				'post_status'  => 'publish',
				'post_parent'  => $family_id,
				'post_content' => (string) wp_json_encode(
					array(
						'fontFamily' => 'Catalog Sans',
						'fontWeight' => '400',
						'src'        => 'https://example.com/catalog-sans.woff2',
					)
				),
			)
		);
	}
}
