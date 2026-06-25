<?php
/**
 * Integration tests for the image-size abilities.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/list-image-sizes (no-input read) and
 * og-media/regenerate-thumbnails (write over a real uploaded image), plus the
 * not-an-image guard and the permission gate.
 */
final class ImageSizesTest extends TestCase {

	public function test_abilities_are_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-media/list-image-sizes' ) );
		$this->assertNotNull( wp_get_ability( 'og-media/regenerate-thumbnails' ) );
	}

	public function test_list_image_sizes_includes_core_sizes(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-media/list-image-sizes' )->execute();

		$this->assertIsArray( $result );
		$names = wp_list_pluck( $result['sizes'], 'name' );
		$this->assertContains( 'thumbnail', $names );

		foreach ( $result['sizes'] as $size ) {
			if ( 'thumbnail' !== $size['name'] ) {
				continue;
			}

			$this->assertGreaterThan( 0, $size['width'] );
			$this->assertIsBool( $size['crop'] );
		}
	}

	public function test_list_image_sizes_preserves_positioned_crop(): void {
		$this->actingAs( 'administrator' );

		add_image_size( 'catalog_positioned_crop', 100, 100, array( 'left', 'top' ) );

		try {
			$result = wp_get_ability( 'og-media/list-image-sizes' )->execute();
			$this->assertIsArray( $result );

			$match = null;
			foreach ( $result['sizes'] as $size ) {
				if ( 'catalog_positioned_crop' === $size['name'] ) {
					$match = $size;
					break;
				}
			}

			$this->assertNotNull( $match, 'The positioned-crop size should be listed.' );
			$this->assertTrue( $match['crop'], 'A positioned crop is still a hard crop.' );
			$this->assertSame( 'left', $match['crop_x'] );
			$this->assertSame( 'top', $match['crop_y'] );
		} finally {
			remove_image_size( 'catalog_positioned_crop' );
		}
	}

	public function test_regenerate_thumbnails_rebuilds_sizes(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/regenerate-thumbnails' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertNotEmpty( $result['sizes'] );
		$this->assertContains( 'thumbnail', wp_list_pluck( $result['sizes'], 'name' ) );
		$this->assertNotEmpty( $result['edit_link'] );
	}

	public function test_regenerate_rejects_non_image(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'application/pdf' ) );

		$result = wp_get_ability( 'og-media/regenerate-thumbnails' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_an_image', $result->get_error_code() );
	}

	public function test_regenerate_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The minimum: 1 input guard rejects a non-positive id at the schema
		// boundary, before absint() could retarget it to a different object.
		$result = wp_get_ability( 'og-media/regenerate-thumbnails' )->execute( array( 'id' => -3 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-media/list-image-sizes' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
