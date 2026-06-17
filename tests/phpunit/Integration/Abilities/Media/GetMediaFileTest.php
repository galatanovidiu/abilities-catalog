<?php
/**
 * Integration tests for the media/get-media-file ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/get-media-file: base64 read over a real upload, the
 * intermediate-size path/dimension resolution, the missing-attachment 404, the
 * non-image dimensions = 0 case, and the oversized-file file_too_large branch.
 */
final class GetMediaFileTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/get-media-file' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/get-media-file', $ability->get_name() );
	}

	public function test_admin_reads_full_image_bytes(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'mime_type', $result );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertSame( basename( (string) get_attached_file( $attachment_id ) ), $result['filename'] );
		$this->assertNotFalse( base64_decode( $result['data'], true ) );
		$this->assertGreaterThan( 0, $result['width'] );
		$this->assertGreaterThan( 0, $result['height'] );
	}

	public function test_intermediate_size_returns_subsize_dimensions(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$expected = image_get_intermediate_size( $attachment_id, 'thumbnail' );
		$this->assertIsArray( $expected );

		$result = wp_get_ability( 'media/get-media-file' )->execute(
			array(
				'id'   => $attachment_id,
				'size' => 'thumbnail',
			)
		);

		$this->assertIsArray( $result );
		// The returned dimensions describe the thumbnail, not the full image.
		$this->assertSame( (int) $expected['width'], $result['width'] );
		$this->assertSame( (int) $expected['height'], $result['height'] );
		$this->assertSame( basename( (string) $expected['path'] ), $result['filename'] );
	}

	public function test_missing_attachment_is_rejected(): void {
		$this->actingAs( 'administrator' );

		// The object-level read_post guard maps a non-existent ID to do_not_allow,
		// so a missing attachment is rejected at the permission gate.
		$result = wp_get_ability( 'media/get-media-file' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_non_attachment_post_returns_404(): void {
		$this->actingAs( 'administrator' );

		// A readable post that is not an attachment passes the read guard but is
		// rejected by the explicit attachment check in execute().
		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'media/get-media-file' )->execute( array( 'id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'media/get-media-file' )->execute( array( 'id' => -3 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_non_image_reports_zero_dimensions(): void {
		$this->actingAs( 'administrator' );

		// A PDF carries no top-level width/height metadata, so the ability
		// reports 0 dimensions for it.
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/wordpress-gsoc-flyer.pdf' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'application/pdf', $result['mime_type'] );
		$this->assertSame( 0, $result['width'] );
		$this->assertSame( 0, $result['height'] );
	}

	public function test_oversized_file_returns_file_too_large_with_status_and_url(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		// The ceiling is a class constant, so simulate an oversized file by
		// rewriting the upload on disk past the 5 MB limit via WP_Filesystem.
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$path = get_attached_file( $attachment_id );
		$wp_filesystem->put_contents( $path, str_repeat( '0', 5 * 1024 * 1024 + 1 ) );

		$result = wp_get_ability( 'media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 413, $data['status'] );
		$this->assertNotEmpty( $data['source_url'] );
	}
}
