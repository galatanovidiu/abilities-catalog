<?php
/**
 * Integration tests for the comments/get-comment-count ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the count read end-to-end: a real approved comment in, the flat
 * status buckets out, with the conditional capability guard enforced by the
 * Abilities API on execute().
 */
final class GetCommentCountTest extends TestCase {

	/**
	 * Post the seeded comment is attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * The seeded approved comment.
	 *
	 * @var int
	 */
	private int $comment_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id    = self::factory()->post->create();
		$this->comment_id = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'An approved comment.',
				'comment_approved' => 1,
			)
		);
	}

	public function tear_down(): void {
		if ( $this->comment_id > 0 ) {
			wp_delete_comment( $this->comment_id, true );
		}
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/get-comment-count');

		$this->assertNotNull($ability);
		$this->assertSame('comments/get-comment-count', $ability->get_name());
	}

	public function test_admin_reads_single_post_counts(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/get-comment-count')->execute(array('post_id' => $this->post_id));

		$this->assertIsArray($result);
		$this->assertSame($this->post_id, $result['post_id']);
		$this->assertGreaterThanOrEqual(1, $result['approved']);
	}

	public function test_admin_reads_whole_site_counts(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/get-comment-count')->execute(array());

		$this->assertIsArray($result);
		$this->assertSame(0, $result['post_id']);
		$this->assertGreaterThanOrEqual(1, $result['total_comments']);
	}

	public function test_post_author_without_moderation_reads_own_post_counts(): void {
		$author_id = self::factory()->user->create(array('role' => 'author'));
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'On the author post.',
				'comment_approved' => 1,
			)
		);

		wp_set_current_user($author_id);

		// The conditional gate must grant a post's counts to that post's editor
		// even without site-wide moderation rights.
		$this->assertFalse(current_user_can('moderate_comments'));
		$this->assertTrue(current_user_can('edit_post', $post_id));

		$result = wp_get_ability('comments/get-comment-count')->execute(array('post_id' => $post_id));

		$this->assertIsArray($result);
		$this->assertSame($post_id, $result['post_id']);
		$this->assertGreaterThanOrEqual(1, $result['approved']);

		wp_delete_post($post_id, true);
	}

	public function test_author_is_denied_another_users_post_counts(): void {
		$author_id = self::factory()->user->create(array('role' => 'author'));

		// The seeded post is not authored by this user, so they can neither edit
		// it nor moderate comments — the per-post branch must deny.
		wp_set_current_user($author_id);

		$result = wp_get_ability('comments/get-comment-count')->execute(array('post_id' => $this->post_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/get-comment-count')->execute(array());

		$expected = array(
			'post_id',
			'approved',
			'moderated',
			'spam',
			'trash',
			'post_trashed',
			'total_comments',
			'all',
		);
		sort($expected);
		$actual = array_keys($result);
		sort($actual);

		$this->assertSame($expected, $actual, 'Result must carry exactly the closed status-bucket field set.');
		$this->assertIsInt($result['approved']);
		$this->assertIsInt($result['post_trashed']);
		$this->assertIsInt($result['all']);
	}

	public function test_unknown_post_id_returns_specific_404(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/get-comment-count')->execute(array('post_id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_post_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_subscriber_is_denied_whole_site_count(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/get-comment-count')->execute(array());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/get-comment-count')->execute(array());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}
}
