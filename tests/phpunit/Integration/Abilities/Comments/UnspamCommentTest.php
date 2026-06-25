<?php
/**
 * Integration tests for the og-comments/unspam-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the unspam-comment write ability end-to-end: a spam comment is
 * restored to the status saved when it was spammed, the capability guard is
 * enforced on execute(), the prior status is captured, and the wrong-state guard
 * rejects a non-spam comment with a 409 `rest_comment_wrong_state` error without
 * mutating it.
 */
final class UnspamCommentTest extends TestCase {

	/**
	 * Post the comments are attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-comments/unspam-comment');

		$this->assertNotNull($ability);
		$this->assertSame('og-comments/unspam-comment', $ability->get_name());
	}

	public function test_admin_can_unspam_comment_restoring_saved_status(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Was approved, then spammed.',
				'comment_approved' => 'spam',
			)
		);
		// Mirror what wp_spam_comment stores so unspam can restore it.
		add_comment_meta($comment_id, '_wp_trash_meta_status', '1');

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertIsArray($result);
		$this->assertSame(array('id', 'status', 'previous_status'), array_keys($result));
		$this->assertSame($comment_id, $result['id']);
		$this->assertSame('approved', $result['status']);
		$this->assertSame('spam', $result['previous_status']);
	}

	public function test_unspam_without_saved_status_falls_back_to_hold(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Spam with no saved prior status.',
				'comment_approved' => 'spam',
			)
		);

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertIsArray($result);
		$this->assertSame('hold', $result['status']);
		$this->assertSame('spam', $result['previous_status']);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => 'spam',
			)
		);

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_non_moderator_is_denied_with_403(): void {
		$this->actingAs('subscriber');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => 'spam',
			)
		);

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_edit', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
	}

	/**
	 * B4 regression: a non-moderator passing a missing comment id receives the
	 * specific 404, not a generic permission failure (404 ordered before 403).
	 */
	public function test_non_moderator_missing_id_returns_404_not_generic(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}

	/**
	 * Wrong-state guard: unspam on an approved, non-spam comment is rejected with a
	 * 409 `rest_comment_wrong_state` error and the comment is left untouched. The
	 * ability accepts only a comment currently in `spam` status; calling it on an
	 * approved comment must not demote it.
	 */
	public function test_unspam_on_approved_comment_is_rejected_with_409(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Approved, never spammed.',
				'comment_approved' => '1',
			)
		);

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_wrong_state', $result->get_error_code());
		$this->assertSame(409, $result->get_error_data()['status']);

		// No mutation: the comment is still approved.
		$this->assertSame('approved', wp_get_comment_status($comment_id));
		$this->assertSame('1', get_comment($comment_id)->comment_approved);
	}

	/**
	 * The other half of the wrong-state guard: unspam on a comment already at hold
	 * (`0`) and non-spam is rejected with the same 409 `rest_comment_wrong_state`
	 * error and the comment is left at hold.
	 */
	public function test_unspam_on_held_comment_is_rejected_with_409(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Held, never spammed.',
				'comment_approved' => '0',
			)
		);

		$result = wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_wrong_state', $result->get_error_code());
		$this->assertSame(409, $result->get_error_data()['status']);

		// No mutation: the comment is still on hold (unapproved).
		$this->assertSame('unapproved', wp_get_comment_status($comment_id));
		$this->assertSame('0', get_comment($comment_id)->comment_approved);
	}

	/**
	 * Regression guard for B3: unspamming must not rewrite the stored commenter IP.
	 */
	public function test_unspamming_preserves_comment_author_ip(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'   => $this->post_id,
				'comment_content'   => 'Spam comment with a recorded IP.',
				'comment_approved'  => 'spam',
				'comment_author_IP' => '203.0.113.45',
			)
		);

		wp_get_ability('og-comments/unspam-comment')->execute(array('id' => $comment_id));

		$this->assertSame('203.0.113.45', get_comment($comment_id)->comment_author_IP);
	}
}
