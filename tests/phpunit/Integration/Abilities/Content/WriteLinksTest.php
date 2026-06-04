<?php
/**
 * Integration tests for D5: edit_link + title on write responses.
 *
 * Proves create/update/restore content abilities return an actionable wp-admin
 * `edit_link` (and a `title`) so an agent can hand a human a link to review the
 * change. Trash and delete abilities return a `title` but no `edit_link`: a
 * deleted post no longer exists, and a trashed post cannot be opened in the
 * editor (wp-admin returns HTTP 409) until it is restored.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises the output shape of the content write abilities.
 */
final class WriteLinksTest extends TestCase {

	public function test_create_post_returns_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-post' )->execute(
			array(
				'title'   => 'Draft one',
				'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Draft one', $result['title'] );
		$this->assertNotEmpty( $result['edit_link'] );
		$this->assertStringContainsString( 'post.php', $result['edit_link'] );
		$this->assertStringContainsString( (string) $result['id'], $result['edit_link'] );
	}

	public function test_create_post_returns_slug_featured_media_and_terms(): void {
		$this->actingAs( 'administrator' );

		$category_id = self::factory()->category->create();
		$tag_id      = self::factory()->tag->create();
		$media_id    = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$result = wp_get_ability( 'content/create-post' )->execute(
			array(
				'title'          => 'Tagged draft',
				'content'        => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'slug'           => 'tagged-draft',
				'categories'     => array( $category_id ),
				'tags'           => array( $tag_id ),
				'featured_media' => $media_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'tagged-draft', $result['slug'] );
		$this->assertSame( $media_id, $result['featured_media'] );
		$this->assertSame( array( $category_id ), $result['categories'] );
		$this->assertSame( array( $tag_id ), $result['tags'] );
	}

	public function test_create_post_returns_empty_terms_when_none_assigned(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-post' )->execute(
			array( 'title' => 'Plain draft' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['featured_media'] );
		$this->assertSame( array(), $result['tags'] );
		$this->assertIsArray( $result['categories'] );
	}

	public function test_create_page_returns_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-page' )->execute( array( 'title' => 'A page' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'A page', $result['title'] );
		$this->assertNotEmpty( $result['edit_link'] );
	}

	public function test_create_cpt_item_returns_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Via cpt',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Via cpt', $result['title'] );
		$this->assertNotEmpty( $result['edit_link'] );
	}

	public function test_update_post_returns_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Old' ) );

		$result = wp_get_ability( 'content/update-post' )->execute(
			array(
				'id'    => $post_id,
				'title' => 'New title',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New title', $result['title'] );
		$this->assertNotEmpty( $result['edit_link'] );
		$this->assertStringContainsString( (string) $post_id, $result['edit_link'] );
	}

	public function test_update_page_returns_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Old page',
			)
		);

		$result = wp_get_ability( 'content/update-page' )->execute(
			array(
				'id'    => $page_id,
				'title' => 'New page',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New page', $result['title'] );
		$this->assertNotEmpty( $result['edit_link'] );
	}

	public function test_update_cpt_item_returns_title_and_edit_link(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Old cpt' ) );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'post',
				'id'        => $post_id,
				'title'     => 'New cpt',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'New cpt', $result['title'] );
		$this->assertNotEmpty( $result['edit_link'] );
	}

	public function test_trash_post_returns_title_and_status_without_edit_link(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'To trash',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'content/trash-post' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'To trash', $result['title'] );
		$this->assertSame( 'trash', $result['status'] );
		// A trashed post cannot be edited (wp-admin returns HTTP 409), so no
		// edit_link is returned; it would dead-end.
		$this->assertArrayNotHasKey( 'edit_link', $result );
	}

	public function test_delete_post_echoes_title_without_edit_link(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Doomed' ) );

		$result = wp_get_ability( 'content/delete-post' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( 'Doomed', $result['title'] );
		$this->assertArrayNotHasKey( 'edit_link', $result );
	}

	public function test_restore_post_revision_returns_edit_link(): void {
		$this->actingAs( 'administrator' );
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

		$result = wp_get_ability( 'content/restore-post-revision' )->execute(
			array(
				'parent'      => $post_id,
				'revision_id' => $revision_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['restored'] );
		$this->assertNotEmpty( $result['edit_link'] );
		$this->assertStringContainsString( (string) $post_id, $result['edit_link'] );
	}
}
