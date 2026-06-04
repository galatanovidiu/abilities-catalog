<?php
/**
 * Integration tests for the comments/delete-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the destructive delete ability end-to-end: real comment in,
 * permanent delete out, with the negative-ID guard and the flattened
 * prior-comment fields verified.
 */
final class DeleteCommentTest extends TestCase {

	/**
	 * Post the comment is attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Comment under test.
	 *
	 * @var int
	 */
	private int $comment_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id    = self::factory()->post->create();
		$this->comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'A doomed comment body.',
				'comment_author'   => 'Jane Doe',
				'comment_approved' => '1',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'comments/delete-comment' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'comments/delete-comment', $ability->get_name() );
	}

	public function test_admin_can_permanently_delete_comment(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'comments/delete-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $this->comment_id, $result['id'] );
		$this->assertSame( $this->post_id, $result['post'] );
		$this->assertSame( 0, $result['parent'] );
		$this->assertSame( 'Jane Doe', $result['author_name'] );
		$this->assertStringContainsString( 'A doomed comment body.', $result['content'] );

		$this->assertNull( get_comment( $this->comment_id ) );
	}

	public function test_negative_id_is_rejected_before_deletion(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'comments/delete-comment' )->execute( array( 'id' => -$this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotNull( get_comment( $this->comment_id ) );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'comments/delete-comment' )->execute( array( 'id' => $this->comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotNull( get_comment( $this->comment_id ) );
	}
}
