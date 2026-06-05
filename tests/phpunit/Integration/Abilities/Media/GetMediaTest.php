<?php
/**
 * Integration tests for the media/get-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/get-media: flat output shape over a real upload, the
 * metadata-less object cast for media_details, and the minimum: 1 input guard.
 */
final class GetMediaTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/get-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/get-media', $ability->get_name() );
	}

	public function test_admin_reads_media_with_flat_fields(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		wp_update_post(
			array(
				'ID'         => $attachment_id,
				'post_title' => 'Canola Field',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'A canola field' );

		$result = wp_get_ability( 'media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertSame( 'Canola Field', $result['title'] );
		$this->assertSame( 'A canola field', $result['alt_text'] );
		$this->assertSame( 'image', $result['media_type'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertNotEmpty( $result['source_url'] );
		// A real image carries metadata, so media_details is a populated array.
		$this->assertIsArray( $result['media_details'] );
		$this->assertArrayHasKey( 'width', $result['media_details'] );
	}

	public function test_metadata_less_attachment_returns_media_details_as_object(): void {
		$this->actingAs( 'administrator' );

		// A bare attachment with no generated metadata: core supplies an empty
		// stdClass for media_details, which the ability must keep as an object so
		// it serializes to {} (not []) under the type: object output schema.
		$attachment_id = self::factory()->attachment->create();

		$result = wp_get_ability( 'media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertIsObject( $result['media_details'] );
		$this->assertSame( '{}', wp_json_encode( $result['media_details'] ) );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The minimum: 1 input guard rejects a non-positive id at the schema
		// boundary, before absint() could retarget it to a different object.
		$result = wp_get_ability( 'media/get-media' )->execute( array( 'id' => -7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
