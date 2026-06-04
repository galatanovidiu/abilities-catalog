<?php
/**
 * Integration tests for the comments/spam-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the spam-comment write ability end-to-end: a held comment is marked
 * as spam, the capability guard is enforced on execute(), the prior moderation
 * status is captured, and the already-spam error path is documented.
 */
final class SpamCommentTest extends TestCase {

	/**
	 * Post the comment is attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Held (unapproved) comment under test.
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
				'comment_content'  => 'A held comment body.',
				'comment_approved' => '0',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/spam-comment');

		$this->assertNotNull($ability);
		$this->assertSame('comments/spam-comment', $ability->get_name());
	}

	public function test_admin_can_spam_held_comment(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/spam-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame($this->comment_id, $result['id']);
		$this->assertSame('spam', $result['status']);
	}

	public function test_output_reports_previous_status(): void {
		$this->actingAs('administrator');

		$approved_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Approved before spamming.',
				'comment_approved' => '1',
			)
		);

		$result = wp_get_ability('comments/spam-comment')->execute(array('id' => $approved_id));

		$this->assertIsArray($result);
		$this->assertSame(array('id', 'status', 'previous_status'), array_keys($result));
		$this->assertSame('spam', $result['status']);
		$this->assertSame('approved', $result['previous_status']);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/spam-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_non_moderator_is_denied(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/spam-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	/**
	 * Documents current behavior for an already-spam comment. The REST controller
	 * always populates `comment_author_IP` in prepare_item_for_database, so the
	 * status-only branch (which would 500 on an unchanged status) is never taken;
	 * the update succeeds and the ability reports the existing `spam` status. This
	 * asserts the present contract, not a desired one.
	 */
	public function test_respamming_already_spam_comment_keeps_spam_status(): void {
		$this->actingAs('administrator');

		$spam_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Already spam.',
				'comment_approved' => 'spam',
			)
		);

		$result = wp_get_ability('comments/spam-comment')->execute(array('id' => $spam_id));

		$this->assertIsArray($result);
		$this->assertSame('spam', $result['status']);
		$this->assertSame('spam', $result['previous_status']);
	}
}
