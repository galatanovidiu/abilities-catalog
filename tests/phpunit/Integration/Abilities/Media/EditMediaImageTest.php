<?php
/**
 * Integration tests for the og-media/edit-media-image ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/edit-media-image: a rotation edit over a real uploaded image
 * (happy path + output shape), the missing-object guard, and the capability gate.
 */
final class EditMediaImageTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-media/edit-media-image' ) );
	}

	public function test_rotation_creates_new_attachment(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$src = wp_get_attachment_image_url( $attachment_id, 'full' );
		$this->assertIsString( $src );

		$result = wp_get_ability( 'og-media/edit-media-image' )->execute(
			array(
				'id'       => $attachment_id,
				'src'      => $src,
				'rotation' => 90,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayHasKey( 'source_url', $result );
		$this->assertIsInt( $result['id'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertNotSame( $attachment_id, $result['id'] );
		$this->assertNotEmpty( $result['source_url'] );

		// The original attachment is preserved.
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
	}

	public function test_missing_attachment_surfaces_route_error_not_generic(): void {
		$this->actingAs( 'administrator' );

		// The admin clears the coarse upload_files guard, so a non-existent id now
		// reaches the route and surfaces its specific error rather than being collapsed
		// to the opaque ability_invalid_permissions an object-level pre-check produced.
		$result = wp_get_ability( 'og-media/edit-media-image' )->execute(
			array(
				'id'  => 999999,
				'src' => 'https://example.com/missing.jpg',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_author_cannot_edit_other_users_image_route_403(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );
		$src = wp_get_attachment_image_url( $attachment_id, 'full' );

		// A different user who holds upload_files (the coarse floor) but not
		// edit_others_posts is still denied by the route's object-level edit_post
		// check — a specific 403, not the generic collapse.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'og-media/edit-media-image' )->execute(
			array(
				'id'       => $attachment_id,
				'src'      => $src,
				'rotation' => 90,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );
		$src = wp_get_attachment_image_url( $attachment_id, 'full' );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-media/edit-media-image' )->execute(
			array(
				'id'       => $attachment_id,
				'src'      => $src,
				'rotation' => 90,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
