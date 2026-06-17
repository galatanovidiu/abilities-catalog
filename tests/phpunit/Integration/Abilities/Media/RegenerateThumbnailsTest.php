<?php
/**
 * Integration tests for the media/regenerate-thumbnails ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises media/regenerate-thumbnails: the happy path, the coarse upload_files
 * gate, and the relocated object-level edit_post guard in execute() — a missing
 * id surfaces the specific 404 and a cross-owner caller is denied with 403
 * without rewriting the attachment's derivatives.
 */
final class RegenerateThumbnailsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'media/regenerate-thumbnails' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'media/regenerate-thumbnails', $ability->get_name() );
	}

	public function test_admin_regenerates_sizes(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'media/regenerate-thumbnails' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertIsArray( $result['sizes'] );
		$this->assertNotEmpty( $result['sizes'] );
		$this->assertNotEmpty( $result['edit_link'] );
	}

	public function test_author_regenerating_missing_id_surfaces_route_404(): void {
		// An author holds upload_files (the coarse floor), so a non-existent id now
		// reaches execute() and returns its specific invalid-id 404 instead of the
		// opaque ability_invalid_permissions the object pre-check produced.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'media/regenerate-thumbnails' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_subscriber_is_denied_by_coarse_gate(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		// A subscriber lacks upload_files, so the coarse permission_callback denies —
		// the generic collapse is correct here: they cannot use the media library.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'media/regenerate-thumbnails' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_author_cannot_regenerate_other_users_image_403_no_mutation(): void {
		$this->actingAs( 'administrator' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );
		$before = wp_get_attachment_metadata( $attachment_id );

		// The author clears the coarse upload_files gate but does not own the
		// attachment and lacks edit_others_posts, so the relocated object guard in
		// execute() denies with a specific 403 and leaves the metadata untouched.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'media/regenerate-thumbnails' )->execute( array( 'id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( $before, wp_get_attachment_metadata( $attachment_id ) );
	}
}
