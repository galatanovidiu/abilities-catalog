<?php
/**
 * Integration tests for the media/delete-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the destructive, no-Trash media delete: happy path with previous_*
 * identity reporting, capability denial, and rejection of non-positive IDs
 * without absint() retargeting.
 */
final class DeleteMediaTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/delete-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/delete-media', $ability->get_name() );
	}

	public function test_admin_deletes_media_and_reports_previous_identity(): void {
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

		$result = wp_get_ability( 'media/delete-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertSame( 'Canola Field', $result['previous_title'] );
		$this->assertSame( 'A canola field', $result['previous_alt_text'] );
		$this->assertSame( 'image', $result['previous_media_type'] );
		$this->assertSame( 'image/jpeg', $result['previous_mime_type'] );
		$this->assertNotEmpty( $result['previous_source_url'] );
		$this->assertNull( get_post( $attachment_id ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$attachment_id = self::factory()->attachment->create();

		$result = wp_get_ability( 'media/delete-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotNull( get_post( $attachment_id ) );
	}

	public function test_non_positive_id_is_rejected_as_invalid_input(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create();

		$result = wp_get_ability( 'media/delete-media' )->execute( array( 'id' => 0 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		// A negative ID must be rejected by input validation (minimum: 1), never
		// coerced to a positive object by absint() and silently deleted.
		$result = wp_get_ability( 'media/delete-media' )->execute( array( 'id' => -$attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotNull( get_post( $attachment_id ) );
	}
}
