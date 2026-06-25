<?php
/**
 * Integration tests for the og-media/detach-featured-image ability.
 *
 * Detaching removes only the post-to-attachment association; the attachment
 * survives. A post with no featured image is a benign no-op (detached:false).
 * The object-level edit_post guard lives in execute() because the wrapped core
 * function delete_post_thumbnail() checks no capability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the detach happy path, no-op, object guards, and permission denial.
 */
final class DetachFeaturedImageTest extends TestCase {

	/**
	 * Creates an image attachment whose wp_get_attachment_image() is non-empty.
	 *
	 * @return int The attachment ID.
	 */
	private function makeImageAttachment(): int {
		return self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-media/detach-featured-image' ) );
	}

	public function test_detach_removes_featured_image(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = $this->makeImageAttachment();
		$post_id       = self::factory()->post->create();
		set_post_thumbnail( $post_id, $attachment_id );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );

		$result = wp_get_ability( 'og-media/detach-featured-image' )->execute(
			array( 'post_id' => $post_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['detached'] );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( array( 'post_id', 'detached' ), array_keys( $result ) );

		// The association is gone; the attachment itself survives.
		$this->assertFalse( (bool) get_post_thumbnail_id( $post_id ) );
		$this->assertNotNull( get_post( $attachment_id ) );
	}

	public function test_detach_with_no_featured_image_is_a_no_op(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'og-media/detach-featured-image' )->execute(
			array( 'post_id' => $post_id )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['detached'] );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	public function test_missing_post_returns_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-media/detach-featured-image' )->execute(
			array( 'post_id' => 99999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_author_who_cannot_edit_is_denied_and_image_survives(): void {
		// Post owned by another author; the acting author cannot edit it.
		$owner_id      = self::factory()->user->create( array( 'role' => 'author' ) );
		$attachment_id = $this->makeImageAttachment();
		$post_id       = self::factory()->post->create(
			array( 'post_author' => $owner_id )
		);
		set_post_thumbnail( $post_id, $attachment_id );

		$this->actingAs( 'author' );

		$result = wp_get_ability( 'og-media/detach-featured-image' )->execute(
			array( 'post_id' => $post_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The featured image survives the denied write.
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'og-media/detach-featured-image' )->execute(
			array( 'post_id' => $post_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
