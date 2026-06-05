<?php
/**
 * Integration tests for content/get-post-revision.
 *
 * Covers the happy-path flat field set, the specific invalid-id 404 (so a
 * missing revision is not collapsed to a generic permission failure), and the
 * cross-author 403 denial inherited from the parent post's edit_post check.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises content/get-post-revision output and error contract.
 */
final class GetPostRevisionTest extends TestCase {

	private const MISSING_ID = 999999;

	/**
	 * Creates a parent post with one revision and returns both IDs.
	 *
	 * @param int $author The post author user ID.
	 * @return array{0:int,1:int} Parent post ID and revision ID.
	 */
	private function createRevision( int $author ): array {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_title'   => 'Revision parent',
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

	public function test_output_returns_flat_revision_fields(): void {
		$admin = $this->actingAs( 'administrator' );

		[ $post_id, $revision_id ] = $this->createRevision( $admin );

		$result = wp_get_ability( 'content/get-post-revision' )->execute(
			array(
				'parent' => $post_id,
				'id'     => $revision_id,
			)
		);

		$this->assertIsArray( $result );
		foreach ( array( 'id', 'parent', 'title', 'content', 'excerpt', 'date', 'modified' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertSame( $revision_id, $result['id'] );
		$this->assertSame( $post_id, $result['parent'] );
		// The latest revision captures the most recent saved content (v2).
		$this->assertStringContainsString( 'v2', $result['content'] );
	}

	public function test_edit_context_returns_raw_block_markup(): void {
		$admin = $this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $admin,
				'post_title'   => 'Rev raw parent',
				'post_content' => '<!-- wp:paragraph --><p>v1</p><!-- /wp:paragraph -->',
			)
		);
		$markup = '<!-- wp:paragraph --><p>v2 block</p><!-- /wp:paragraph -->';
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $markup,
				'post_title'   => 'Rev raw v2',
				'post_excerpt' => 'Rev raw excerpt',
			)
		);
		$revisions   = wp_get_post_revisions( $post_id );
		$revision_id = (int) array_key_first( $revisions );

		$result = wp_get_ability( 'content/get-post-revision' )->execute(
			array(
				'parent'  => $post_id,
				'id'      => $revision_id,
				'context' => 'edit',
			)
		);

		$this->assertIsArray( $result );
		// The latest revision stores the most recent block markup; edit context
		// exposes it as flat *_raw fields.
		$this->assertArrayHasKey( 'content_raw', $result );
		$this->assertSame( $markup, $result['content_raw'] );
		$this->assertSame( 'Rev raw v2', $result['title_raw'] );
		$this->assertSame( 'Rev raw excerpt', $result['excerpt_raw'] );
	}

	public function test_view_context_omits_raw_fields(): void {
		$admin = $this->actingAs( 'administrator' );

		[ $post_id, $revision_id ] = $this->createRevision( $admin );

		// Default (view) context: core does not return *.raw for revisions.
		$result = wp_get_ability( 'content/get-post-revision' )->execute(
			array(
				'parent' => $post_id,
				'id'     => $revision_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'content_raw', $result );
		$this->assertArrayNotHasKey( 'title_raw', $result );
		$this->assertArrayNotHasKey( 'excerpt_raw', $result );
	}

	public function test_missing_revision_id_returns_specific_404_not_permission(): void {
		$admin = $this->actingAs( 'administrator' );

		[ $post_id ] = $this->createRevision( $admin );

		$result = wp_get_ability( 'content/get-post-revision' )->execute(
			array(
				'parent' => $post_id,
				'id'     => self::MISSING_ID,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}

	public function test_author_denied_on_other_authors_revision(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );

		[ $post_id, $revision_id ] = $this->createRevision( $owner );

		// A different author cannot read the revision: the wrapped route enforces
		// edit_post on the parent, surfacing a specific 403 rather than collapsing.
		$this->actingAs( 'author' );
		$result = wp_get_ability( 'content/get-post-revision' )->execute(
			array(
				'parent' => $post_id,
				'id'     => $revision_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_read', $result->get_error_code() );
	}
}
