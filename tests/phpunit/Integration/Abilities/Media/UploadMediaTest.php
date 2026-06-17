<?php
/**
 * Integration tests for the media/upload-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/upload-media: a single-create happy path with metadata in the
 * flat output, the oversized-payload 413 guard, invalid base64, the capability
 * gate, and core's disallowed-file-type rejection (not collapsed to permission).
 */
final class UploadMediaTest extends TestCase {

	/**
	 * Base64 of a 1x1 PNG used for the happy-path upload.
	 *
	 * @var string
	 */
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/upload-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/upload-media', $ability->get_name() );
	}

	public function test_admin_uploads_png_and_gets_flat_metadata(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'media/upload-media' )->execute(
			array(
				'file'        => self::PNG_BASE64,
				'filename'    => 'pixel.png',
				'title'       => 'Pixel Title',
				'alt_text'    => 'Pixel alt',
				'caption'     => 'Pixel caption',
				'description' => 'Pixel description',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'attachment', get_post_type( $result['id'] ) );
		$this->assertNotEmpty( $result['source_url'] );
		$this->assertSame( 'image', $result['media_type'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'Pixel Title', $result['title'] );
		$this->assertSame( 'Pixel alt', $result['alt_text'] );
		$this->assertStringContainsString( 'Pixel caption', $result['caption'] );
		$this->assertStringContainsString( 'Pixel description', $result['description'] );
		$this->assertSame( 0, $result['post'] );
	}

	public function test_oversized_payload_returns_413(): void {
		$this->actingAs( 'administrator' );

		// 12 MiB of base64 estimates to ~9 MiB decoded (> 8 MiB cap), so the
		// size precheck rejects it before base64_decode runs.
		$oversized = str_repeat( 'A', 12 * 1024 * 1024 );

		$result = wp_get_ability( 'media/upload-media' )->execute(
			array(
				'file'     => $oversized,
				'filename' => 'big.png',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_file_too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
	}

	public function test_invalid_base64_returns_400(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'media/upload-media' )->execute(
			array(
				'file'     => '!!! not base64 !!!',
				'filename' => 'broken.png',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_invalid_file', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'media/upload-media' )->execute(
			array(
				'file'     => self::PNG_BASE64,
				'filename' => 'pixel.png',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_author_uploading_to_unowned_parent_surfaces_route_403_not_generic(): void {
		$owner_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$parent_id = self::factory()->post->create(
			array(
				'post_author' => $owner_id,
				'post_status' => 'publish',
			)
		);

		// The author clears the coarse upload_files guard but cannot edit the supplied
		// parent (no edit_others_posts), so the route's object-level parent check denies
		// with a specific 403 instead of the generic collapse.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'media/upload-media' )->execute(
			array(
				'file'     => self::PNG_BASE64,
				'filename' => 'pixel.png',
				'post'     => $parent_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_disallowed_file_type_is_rejected_by_core(): void {
		$this->actingAs( 'administrator' );

		// A .php payload is not an allowed upload type; core rejects it with a
		// specific error rather than a permission denial.
		$result = wp_get_ability( 'media/upload-media' )->execute(
			array(
				'file'     => base64_encode( '<?php echo 1;' ),
				'filename' => 'evil.php',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
