<?php
/**
 * Integration tests for the comments/unapprove-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the unapprove write ability end-to-end: an approved comment is
 * forced to "hold", the capability guard is enforced on execute(), the prior
 * status is reported, and the current already-held no-op behavior is documented.
 */
final class UnapproveCommentTest extends TestCase {

	/**
	 * Post the comment is attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Approved comment under test.
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
				'comment_content'  => 'An approved comment body.',
				'comment_approved' => '1',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/unapprove-comment');

		$this->assertNotNull($ability);
		$this->assertSame('comments/unapprove-comment', $ability->get_name());
	}

	public function test_admin_can_unapprove_approved_comment(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/unapprove-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame($this->comment_id, $result['id']);
		$this->assertSame('hold', $result['status']);
		$this->assertSame('approved', $result['previous_status']);
	}

	public function test_output_shape_has_id_status_and_previous_status(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/unapprove-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame(array('id', 'status', 'previous_status'), array_keys($result));
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/unapprove-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_non_moderator_is_denied_with_403(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/unapprove-comment')->execute(array('id' => $this->comment_id));

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

		$result = wp_get_ability('comments/unapprove-comment')->execute(array('id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}

	/**
	 * An already-held comment is a no-op: the ability skips the status change and
	 * reports the existing `hold` status rather than an error.
	 */
	public function test_unapproving_already_held_comment_keeps_hold_status(): void {
		$this->actingAs('administrator');

		$held_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Already held.',
				'comment_approved' => '0',
			)
		);

		$result = wp_get_ability('comments/unapprove-comment')->execute(array('id' => $held_id));

		$this->assertIsArray($result);
		$this->assertSame('hold', $result['status']);
	}

	/**
	 * Regression guard for B3: unapproving must not rewrite the stored commenter IP.
	 */
	public function test_unapproving_preserves_comment_author_ip(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'   => $this->post_id,
				'comment_content'   => 'Approved comment with a recorded IP.',
				'comment_approved'  => '1',
				'comment_author_IP' => '203.0.113.45',
			)
		);

		wp_get_ability('comments/unapprove-comment')->execute(array('id' => $comment_id));

		$this->assertSame('203.0.113.45', get_comment($comment_id)->comment_author_IP);
	}
}
