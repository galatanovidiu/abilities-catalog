<?php
/**
 * Integration tests for the og-media/get-media-file ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/get-media-file: base64 read over a real upload, the
 * intermediate-size path/dimension resolution, the missing-attachment 404, the
 * non-image dimensions = 0 case, and the oversized-file file_too_large branch.
 */
final class GetMediaFileTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-media/get-media-file' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-media/get-media-file', $ability->get_name() );
	}

	public function test_admin_reads_full_image_bytes(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

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

		$result = wp_get_ability( 'og-media/get-media-file' )->execute(
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

	public function test_missing_attachment_surfaces_specific_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// With the object guard relocated into execute(), a non-existent ID reaches the
		// explicit attachment check and returns the specific invalid_attachment 404
		// instead of the opaque ability_invalid_permissions the gate produced before.
		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_subscriber_cannot_read_private_attachment_gets_403(): void {
		$owner_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$parent_id     = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $owner_id,
			)
		);
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent_id );
		$this->assertIsInt( $attachment_id );

		// The attachment inherits the private parent's visibility. A subscriber cannot
		// read it, so the relocated object guard in execute() denies with a specific
		// 403 — the guard is not weakened by coarsening permission_callback.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_non_attachment_post_returns_404(): void {
		$this->actingAs( 'administrator' );

		// A readable post that is not an attachment passes the read guard but is
		// rejected by the explicit attachment check in execute().
		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => -3 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_non_image_reports_zero_dimensions(): void {
		$this->actingAs( 'administrator' );

		// A PDF carries no top-level width/height metadata, so the ability
		// reports 0 dimensions for it.
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/wordpress-gsoc-flyer.pdf' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

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

		$result = wp_get_ability( 'og-media/get-media-file' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 413, $data['status'] );
		$this->assertNotEmpty( $data['source_url'] );
	}
}
