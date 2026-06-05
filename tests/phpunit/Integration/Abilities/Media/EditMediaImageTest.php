<?php
/**
 * Integration tests for the media/edit-media-image ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/edit-media-image: a rotation edit over a real uploaded image
 * (happy path + output shape), the missing-object guard, and the capability gate.
 */
final class EditMediaImageTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'media/edit-media-image' ) );
	}

	public function test_rotation_creates_new_attachment(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$src = wp_get_attachment_image_url( $attachment_id, 'full' );
		$this->assertIsString( $src );

		$result = wp_get_ability( 'media/edit-media-image' )->execute(
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

	public function test_missing_attachment_returns_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'media/edit-media-image' )->execute(
			array(
				'id'  => 999999,
				'src' => 'https://example.com/missing.jpg',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );
		$src = wp_get_attachment_image_url( $attachment_id, 'full' );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'media/edit-media-image' )->execute(
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
