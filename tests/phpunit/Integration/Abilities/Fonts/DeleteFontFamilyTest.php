<?php
/**
 * Integration tests for the og-fonts/delete-font-family ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the destructive, no-Trash delete: happy path with cascade reporting,
 * capability denial, and rejection of non-positive IDs without permission collapse.
 */
final class DeleteFontFamilyTest extends TestCase {

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
					)
				),
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-fonts/delete-font-family' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-fonts/delete-font-family', $ability->get_name() );
	}

	public function test_admin_deletes_family_and_reports_cascade(): void {
		$this->actingAs( 'administrator' );

		$family_id = $this->createFontFamily(
			array(
				'name'       => 'Catalog Sans',
				'slug'       => 'catalog-sans',
				'fontFamily' => '"Catalog Sans", sans-serif',
			)
		);
		$this->createFontFace( $family_id );
		$this->createFontFace( $family_id );

		$result = wp_get_ability( 'og-fonts/delete-font-family' )->execute( array( 'id' => $family_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $family_id, $result['id'] );
		$this->assertSame( 'Catalog Sans', $result['name'] );
		$this->assertSame( 'catalog-sans', $result['slug'] );
		$this->assertSame( 2, $result['font_face_count'] );
		$this->assertNull( get_post( $family_id ) );
	}

	public function test_non_admin_is_denied(): void {
		$this->actingAs( 'editor' );

		$family_id = $this->createFontFamily(
			array(
				'name' => 'Locked Family',
				'slug' => 'locked-family',
			)
		);

		$result = wp_get_ability( 'og-fonts/delete-font-family' )->execute( array( 'id' => $family_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotNull( get_post( $family_id ) );
	}

	public function test_non_positive_id_is_rejected_as_invalid_input(): void {
		$this->actingAs( 'administrator' );

		$family_id = $this->createFontFamily(
			array(
				'name' => 'Survivor',
				'slug' => 'survivor',
			)
		);

		$result = wp_get_ability( 'og-fonts/delete-font-family' )->execute( array( 'id' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		// A negative ID must be rejected by input validation (minimum: 1), never
		// coerced to a positive object by absint() and silently deleted.
		$result = wp_get_ability( 'og-fonts/delete-font-family' )->execute( array( 'id' => -$family_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotNull( get_post( $family_id ) );
	}
}
