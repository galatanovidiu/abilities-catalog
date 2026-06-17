<?php
/**
 * Integration tests for the fonts/get-font-family ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the read: registration, capability guard, and the shaped output
 * (settings object with semantic fields, integer font-face IDs).
 */
final class GetFontFamilyTest extends TestCase {

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
						'fontFamily' => 'Inter',
						'fontWeight' => '400',
						'src'        => 'https://example.com/inter.woff2',
					)
				),
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'fonts/get-font-family' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'fonts/get-font-family', $ability->get_name() );
	}

	public function test_admin_reads_family_with_shaped_output(): void {
		$this->actingAs( 'administrator' );

		$family_id = $this->createFontFamily(
			array(
				'name'       => 'Catalog Sans',
				'slug'       => 'catalog-sans',
				'fontFamily' => '"Catalog Sans", sans-serif',
			)
		);
		$face_one  = $this->createFontFace( $family_id );
		$face_two  = $this->createFontFace( $family_id );

		$result = wp_get_ability( 'fonts/get-font-family' )->execute( array( 'id' => $family_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $family_id, $result['id'] );
		$this->assertIsArray( $result['font_family_settings'] );
		$this->assertSame( 'Catalog Sans', $result['font_family_settings']['name'] );
		$this->assertSame( 'catalog-sans', $result['font_family_settings']['slug'] );

		// font_faces are integer post IDs, per the corrected output schema.
		$this->assertIsArray( $result['font_faces'] );
		$this->assertContainsOnly( 'integer', $result['font_faces'] );
		$this->assertContains( $face_one, $result['font_faces'] );
		$this->assertContains( $face_two, $result['font_faces'] );
	}

	public function test_non_admin_is_denied(): void {
		$this->actingAs( 'editor' );

		$family_id = $this->createFontFamily(
			array(
				'name' => 'Locked Family',
				'slug' => 'locked-family',
			)
		);

		$result = wp_get_ability( 'fonts/get-font-family' )->execute( array( 'id' => $family_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
