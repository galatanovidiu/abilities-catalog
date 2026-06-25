<?php
/**
 * Integration tests for the og-content/restore-post-revision data-loss guard (B6).
 *
 * Restoring is advertised as non-destructive because the pre-restore state is saved
 * as a fresh revision. That guarantee fails when revisions are disabled: no recovery
 * revision is written, so the restore is unrecoverable. The ability refuses that case
 * — revisions off AND the target is not an autosave — mirroring core's own wp-admin
 * restore guard (wp-admin/revision.php:52). Autosaves stay restorable, matching core.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the revisions-disabled rejection and the autosave exception.
 */
final class RestorePostRevisionTest extends TestCase {

	/**
	 * Creates a post, edits it so a revision of the original content exists, and
	 * returns the parent ID plus the revision ID.
	 *
	 * @return array{0:int,1:int} The parent post ID and the revision ID.
	 */
	private function makePostWithRevision(): array {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Versioned',
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

		return array( $post_id, $revision_id );
	}

	public function test_restore_rejected_when_revisions_disabled(): void {
		$this->actingAs( 'administrator' );

		[ $post_id, $revision_id ] = $this->makePostWithRevision();

		// Disable revisions only after the recovery revision already exists.
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$result = wp_get_ability( 'og-content/restore-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $revision_id,
			)
		);

		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_revisions_disabled', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		// The content was not overwritten.
		$this->assertSame( 'v2', get_post( $post_id )->post_content );
	}

	/**
	 * Core lets an autosave be restored even when revisions are disabled, so the
	 * guard must not reject it. Proves the condition mirrors core exactly.
	 */
	public function test_restore_allowed_for_autosave_when_revisions_disabled(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Autosaved',
				'post_content' => 'live',
			)
		);

		require_once ABSPATH . 'wp-admin/includes/post.php';
		$post_data                 = get_post( $post_id, ARRAY_A );
		$post_data['post_ID']      = $post_id;
		$post_data['post_content'] = 'autosaved-draft';
		$autosave_id               = wp_create_post_autosave( wp_slash( $post_data ) );

		$this->assertIsInt( $autosave_id );
		$this->assertGreaterThan( 0, $autosave_id );

		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$result = wp_get_ability( 'og-content/restore-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $autosave_id,
			)
		);

		remove_filter( 'wp_revisions_to_keep', '__return_zero' );

		// Not rejected by the data-loss guard: an autosave is the user's own work.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['restored'] );
	}
}
