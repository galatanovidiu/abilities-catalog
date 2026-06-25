<?php
/**
 * Integration tests for the og-dashboard/get-activity ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the composed read ability: recent published posts and recent
 * approved comments out, with the declared closed item shapes and the
 * capability guard enforced on execute().
 */
final class GetActivityTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-dashboard/get-activity' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-dashboard/get-activity', $ability->get_name() );
	}

	public function test_published_items_use_closed_shape(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Activity Post',
			)
		);

		$result = wp_get_ability( 'og-dashboard/get-activity' )->execute( array( 'number' => 5 ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'published', $result );

		$ids = wp_list_pluck( $result['published'], 'id' );
		$this->assertContains( $post_id, $ids );

		foreach ( $result['published'] as $item ) {
			$this->assertSame( array( 'id', 'title', 'date' ), array_keys( $item ) );
			$this->assertIsInt( $item['id'] );
			$this->assertIsString( $item['title'] );
			$this->assertIsString( $item['date'] );
		}
	}

	public function test_comment_items_use_closed_shape(): void {
		$this->actingAs( 'administrator' );

		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);

		$result = wp_get_ability( 'og-dashboard/get-activity' )->execute( array( 'number' => 5 ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'comments', $result );

		$ids = wp_list_pluck( $result['comments'], 'id' );
		$this->assertContains( $comment_id, $ids );

		foreach ( $result['comments'] as $item ) {
			$this->assertSame( array( 'id', 'post', 'author', 'date', 'excerpt' ), array_keys( $item ) );
			$this->assertIsInt( $item['id'] );
			$this->assertIsInt( $item['post'] );
			$this->assertIsString( $item['author'] );
			$this->assertIsString( $item['date'] );
			$this->assertIsString( $item['excerpt'] );
		}
	}

	public function test_comments_on_inaccessible_private_posts_are_hidden(): void {
		// A private post owned by another user, with an approved comment.
		$owner_id          = self::factory()->user->create( array( 'role' => 'editor' ) );
		$private_post_id   = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $owner_id,
			)
		);
		$hidden_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $private_post_id,
				'comment_approved' => '1',
			)
		);

		// A public post anyone can read, with an approved comment.
		$public_post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$visible_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $public_post_id,
				'comment_approved' => '1',
			)
		);

		// A contributor: holds edit_posts (passes the coarse guard) but cannot
		// edit or read another user's private post.
		$this->actingAs( 'contributor' );

		$result = wp_get_ability( 'og-dashboard/get-activity' )->execute( array( 'number' => 20 ) );

		$this->assertIsArray( $result );
		$ids = wp_list_pluck( $result['comments'], 'id' );
		$this->assertNotContains(
			$hidden_comment_id,
			$ids,
			'Comment on an inaccessible private post must not leak.'
		);
		$this->assertContains(
			$visible_comment_id,
			$ids,
			'Comment on a readable public post must still appear.'
		);
	}

	public function test_administrator_sees_comments_on_private_posts(): void {
		$this->actingAs( 'administrator' );

		$private_post_id = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$comment_id      = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $private_post_id,
				'comment_approved' => '1',
			)
		);

		$result = wp_get_ability( 'og-dashboard/get-activity' )->execute( array( 'number' => 20 ) );

		$ids = wp_list_pluck( $result['comments'], 'id' );
		$this->assertContains( $comment_id, $ids );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-dashboard/get-activity' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
