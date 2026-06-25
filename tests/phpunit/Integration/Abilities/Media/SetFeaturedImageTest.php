<?php
/**
 * Integration tests for the og-media/set-featured-image ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/set-featured-image: the dual object-level guard on both the
 * post and the attachment, the read-back of the set thumbnail, and the
 * specific-error-not-permission-collapse contract.
 */
final class SetFeaturedImageTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-media/set-featured-image' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-media/set-featured-image', $ability->get_name() );
	}

	public function test_admin_sets_featured_image(): void {
		$this->actingAs( 'administrator' );

		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/set-featured-image' )->execute(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'post_id', 'attachment_id', 'set' ), array_keys( $result ) );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $attachment_id, $result['attachment_id'] );
		$this->assertTrue( $result['set'] );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_nonexistent_post_returns_404(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$result = wp_get_ability( 'og-media/set-featured-image' )->execute(
			array(
				'post_id'       => 99999999,
				'attachment_id' => $attachment_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_non_attachment_id_returns_404(): void {
		$this->actingAs( 'administrator' );

		$post_id  = self::factory()->post->create();
		$other_id = self::factory()->post->create();

		$result = wp_get_ability( 'og-media/set-featured-image' )->execute(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $other_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_author_cannot_set_on_other_authors_post(): void {
		$owner   = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $owner ) );

		// The attachment is owned by the owner too, so the only failing guard is
		// the post edit cap for the second author.
		wp_set_current_user( $owner );
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);

		// A different author must not be able to set the featured image.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'og-media/set-featured-image' )->execute(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		// The post was not modified.
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_resetting_same_image_is_idempotent(): void {
		$this->actingAs( 'administrator' );

		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$ability = wp_get_ability( 'og-media/set-featured-image' );
		$first   = $ability->execute(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);
		$this->assertIsArray( $first );
		$this->assertTrue( $first['set'] );

		// Re-setting the SAME image must be a no-op success, not a 400:
		// set_post_thumbnail() returns false on an unchanged value.
		$second = $ability->execute(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);
		$this->assertIsArray( $second );
		$this->assertTrue( $second['set'] );
		$this->assertSame( $attachment_id, $second['attachment_id'] );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_author_who_cannot_edit_the_attachment_is_denied(): void {
		// The attachment is owned by the admin.
		$this->actingAs( 'administrator' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		// The post is owned by an author who can edit it but not the admin's attachment.
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		wp_set_current_user( $author_id );
		// Confirms the test reaches the attachment guard, not the post guard.
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$this->assertFalse( current_user_can( 'edit_post', $attachment_id ) );

		$result = wp_get_ability( 'og-media/set-featured-image' )->execute(
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		// The attachment guard — the value-add over og-content/update-post — fired.
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-media/set-featured-image' )->execute(
			array(
				'post_id'       => 1,
				'attachment_id' => 2,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
