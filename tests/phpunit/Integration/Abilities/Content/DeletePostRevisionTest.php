<?php
/**
 * Integration tests for the content/delete-post-revision ability.
 *
 * Permanently deletes one saved revision of a post. The wrapped core function
 * `wp_delete_post_revision()` performs no capability check, so the ability's
 * execute() carries the object-level `edit_post` guard plus the
 * parent-mismatch / bad-id 404s. These tests prove the happy path actually
 * removes the snapshot, every rejection surfaces a specific (non-collapsed)
 * error, and a denied delete leaves the revision intact.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Post;

/**
 * Exercises delete-post-revision happy path, error specificity, and safety.
 */
final class DeletePostRevisionTest extends TestCase {

	/**
	 * Creates a post owned by the given author, edits its content so a revision
	 * of the original content exists, and returns the parent ID plus the
	 * newest revision ID.
	 *
	 * @param int $author_id Optional author user ID for the post.
	 * @return array{0:int,1:int} The parent post ID and a revision ID.
	 */
	private function makePostWithRevision( int $author_id = 0 ): array {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Versioned',
				'post_content' => 'v1',
				'post_author'  => $author_id,
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

		return array( $post_id, $revision_id );
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'content/delete-post-revision' ) );
	}

	public function test_delete_removes_the_revision(): void {
		$this->actingAs( 'administrator' );

		[ $post_id, $revision_id ] = $this->makePostWithRevision();

		// Sanity: the revision exists before deletion.
		$this->assertInstanceOf( WP_Post::class, wp_get_post_revision( $revision_id ) );

		$result = wp_get_ability( 'content/delete-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $revision_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'deleted', 'post_id', 'revision_id' ), array_keys( $result ) );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( $revision_id, $result['revision_id'] );

		// The snapshot is gone.
		$this->assertNull( wp_get_post_revision( $revision_id ) );
		// The post's current content is untouched.
		$this->assertSame( 'v2', get_post( $post_id )->post_content );

		wp_delete_post( $post_id, true );
	}

	public function test_non_revision_id_returns_invalid_id_404(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create();

		// A normal post is not a revision.
		$result = wp_get_ability( 'content/delete-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $post_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		wp_delete_post( $post_id, true );
	}

	public function test_wrong_parent_returns_mismatch_404(): void {
		$this->actingAs( 'administrator' );

		[ $post_id, $revision_id ] = $this->makePostWithRevision();
		$other_post                = self::factory()->post->create();

		$result = wp_get_ability( 'content/delete-post-revision' )->execute(
			array(
				'parent'      => $other_post,
				'revision_id' => $revision_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_revision_parent_id_mismatch', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The revision survives — the mismatch did not delete it.
		$this->assertInstanceOf( WP_Post::class, wp_get_post_revision( $revision_id ) );

		wp_delete_post( $post_id, true );
		wp_delete_post( $other_post, true );
	}

	public function test_author_without_edit_access_gets_cannot_edit_403_and_revision_survives(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		[ $post_id, $revision_id ] = $this->makePostWithRevision( $admin_id );

		// A different author who cannot edit the admin's post. The coarse
		// permission_callback (edit_posts) passes, so this proves the object-level
		// guard in execute() returns the specific 403, not a permission collapse.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'content/delete-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $revision_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The revision survives the denied delete.
		$this->assertInstanceOf( WP_Post::class, wp_get_post_revision( $revision_id ) );

		wp_delete_post( $post_id, true );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		[ $post_id, $revision_id ] = $this->makePostWithRevision();

		$result = wp_get_ability( 'content/delete-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $revision_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// Nothing was deleted.
		$this->assertInstanceOf( WP_Post::class, wp_get_post_revision( $revision_id ) );

		wp_delete_post( $post_id, true );
	}
}
