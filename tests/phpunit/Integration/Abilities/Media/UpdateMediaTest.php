<?php
/**
 * Integration tests for the media/update-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/update-media: flat output shape after a real update,
 * the source_url and post fields in the result, and the minimum: 1 input guard.
 */
final class UpdateMediaTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/update-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/update-media', $ability->get_name() );
	}

	public function test_admin_updates_media_and_gets_flat_fields(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'media/update-media' )->execute(
			array(
				'id'          => $attachment_id,
				'title'       => 'Updated Canola',
				'alt_text'    => 'Updated alt',
				'caption'     => 'Updated caption',
				'description' => 'Updated description',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertSame( 'Updated Canola', $result['title'] );
		$this->assertSame( 'Updated alt', $result['alt_text'] );
		$this->assertStringContainsString( 'Updated caption', $result['caption'] );
		$this->assertStringContainsString( 'Updated description', $result['description'] );
		$this->assertNotEmpty( $result['source_url'] );
		$this->assertSame( 0, $result['post'] );
	}

	public function test_attach_to_post_is_reflected_in_output(): void {
		$this->actingAs( 'administrator' );

		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'media/update-media' )->execute(
			array(
				'id'   => $attachment_id,
				'post' => $post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post'] );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The minimum: 1 input guard rejects a non-positive id at the schema
		// boundary, before absint() could retarget it to a different object.
		$result = wp_get_ability( 'media/update-media' )->execute( array( 'id' => -7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
