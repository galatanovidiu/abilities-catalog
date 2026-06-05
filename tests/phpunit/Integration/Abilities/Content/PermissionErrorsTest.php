<?php
/**
 * Integration tests for D7 error specificity across the content domain.
 *
 * Proves that the content abilities no longer collapse missing/not-found ids and
 * unknown post types into the generic "does not have necessary permission" error,
 * that object-level capability is still enforced (relocated, not removed), and
 * that anonymous reads of published public posts still work.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the permission/error boundary of the content abilities.
 */
final class PermissionErrorsTest extends TestCase {

	private const MISSING_ID = 999999;

	/**
	 * Asserts the result is a WP_Error whose error code is NOT the generic
	 * Abilities-API permission error — i.e. a specific, actionable error.
	 */
	private function assertSpecificError( $result, ?string $expected_code = null ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Expected a specific error, got the generic permission collapse.'
		);
		if ( null === $expected_code ) {
			return;
		}

		$this->assertSame( $expected_code, $result->get_error_code() );
	}

	public function test_get_post_missing_id_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-post' )->execute( array( 'id' => self::MISSING_ID ) );

		$this->assertSpecificError( $result, 'rest_post_invalid_id' );
	}

	public function test_get_post_anonymous_reads_published_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Public hello',
			)
		);
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'content/get-post' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'Public hello', $result['title'] );
	}

	public function test_get_post_anonymous_denied_on_private_post(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $author,
			)
		);
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'content/get-post' )->execute( array( 'id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_update_post_missing_id_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-post' )->execute(
			array(
				'id'    => self::MISSING_ID,
				'title' => 'Nope',
			)
		);

		$this->assertSpecificError( $result, 'rest_post_invalid_id' );
	}

	public function test_update_post_author_denied_on_other_authors_post(): void {
		$owner   = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $owner,
			)
		);

		// A different author tries to edit it.
		$this->actingAs( 'author' );
		$result = wp_get_ability( 'content/update-post' )->execute(
			array(
				'id'    => $post_id,
				'title' => 'Hijacked',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'Hijacked', get_post( $post_id )->post_title );
	}

	public function test_update_post_subscriber_is_denied(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'content/update-post' )->execute(
			array(
				'id'    => $post_id,
				'title' => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_delete_post_missing_id_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/delete-post' )->execute( array( 'id' => self::MISSING_ID ) );

		$this->assertSpecificError( $result, 'rest_post_invalid_id' );
	}

	public function test_delete_post_negative_id_fails_validation_without_deleting(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// A negative id must be rejected by input validation (minimum: 1), never
		// mapped to a positive post by absint() and permanently deleted.
		$result = wp_get_ability( 'content/delete-post' )->execute( array( 'id' => -$post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_delete_page_missing_id_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/delete-page' )->execute( array( 'id' => self::MISSING_ID ) );

		$this->assertSpecificError( $result, 'rest_post_invalid_id' );
	}

	public function test_get_cpt_item_unknown_post_type_returns_400_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-cpt-item' )->execute(
			array(
				'post_type' => 'not_a_real_type',
				'id'        => self::MISSING_ID,
			)
		);

		$this->assertSpecificError( $result, 'invalid_post_type' );
	}

	public function test_update_cpt_item_unknown_post_type_returns_400_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'not_a_real_type',
				'id'        => self::MISSING_ID,
				'title'     => 'x',
			)
		);

		$this->assertSpecificError( $result, 'invalid_post_type' );
	}

	public function test_get_post_meta_missing_id_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => self::MISSING_ID ) );

		$this->assertSpecificError( $result, 'rest_post_invalid_id' );
	}

	public function test_get_post_meta_author_denied_on_other_authors_post(): void {
		$owner   = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $owner,
			)
		);
		update_post_meta( $post_id, 'secret', 'value' );

		$this->actingAs( 'author' );
		$result = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $post_id ) );

		$this->assertSpecificError( $result, 'rest_cannot_edit' );
	}

	public function test_restore_post_revision_author_denied_on_other_authors_post(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $owner,
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'v2',
			)
		);
		$revisions   = wp_get_post_revisions( $post_id );
		$revision_id = (int) array_key_first( $revisions );

		// A different author must not be able to restore the revision (B1: core
		// wp_restore_post_revision() has no capability check of its own).
		$this->actingAs( 'author' );
		$result = wp_get_ability( 'content/restore-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $revision_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
