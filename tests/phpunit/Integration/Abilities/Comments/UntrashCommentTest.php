<?php
/**
 * Integration tests for the comments/untrash-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the restore-from-trash write ability end-to-end: a trashed comment is
 * untrashed back to its saved pre-trash status, the wrong-state guard rejects a
 * comment that is not in the trash without mutating it, and the capability guard is
 * enforced in execute().
 */
final class UntrashCommentTest extends TestCase {

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

	/**
	 * Creates a comment with the given raw `comment_approved` value, then trashes it
	 * through core so `_wp_trash_meta_status` records the pre-trash status the way the
	 * wp-admin trash flow does.
	 *
	 * @param string $approved Raw comment_approved value before trashing ('1', '0', 'spam').
	 * @return int The trashed comment ID.
	 */
	private function makeTrashedComment(string $approved): int {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'A comment to be trashed.',
				'comment_approved' => $approved,
			)
		);
		wp_trash_comment($comment_id);

		return $comment_id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/untrash-comment');

		$this->assertNotNull($ability);
		$this->assertSame('comments/untrash-comment', $ability->get_name());
	}

	/**
	 * An approved comment that was trashed is restored to `approved` via the saved
	 * `_wp_trash_meta_status`, and the prior (`trash`) status is reported.
	 */
	public function test_admin_untrashes_approved_comment_back_to_approved(): void {
		$this->actingAs('administrator');
		$comment_id = $this->makeTrashedComment('1');

		$this->assertSame('trash', wp_get_comment_status($comment_id));

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $comment_id));

		$this->assertIsArray($result);
		$this->assertSame($comment_id, $result['id']);
		$this->assertSame('approved', $result['status']);
		$this->assertSame('trash', $result['previous_status']);
		$this->assertSame('approved', wp_get_comment_status($comment_id));
	}

	/**
	 * A held (unapproved) comment that was trashed is restored to `hold`, proving the
	 * raw `'0'` meta value maps to the REST `hold` vocabulary, not a literal `'0'`.
	 */
	public function test_untrashes_held_comment_back_to_hold(): void {
		$this->actingAs('administrator');
		$comment_id = $this->makeTrashedComment('0');

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $comment_id));

		$this->assertIsArray($result);
		$this->assertSame('hold', $result['status']);
		$this->assertSame('unapproved', wp_get_comment_status($comment_id));
	}

	/**
	 * Restoring clears the trash bookkeeping meta, matching core behavior.
	 */
	public function test_untrashing_clears_trash_meta(): void {
		$this->actingAs('administrator');
		$comment_id = $this->makeTrashedComment('1');

		wp_get_ability('comments/untrash-comment')->execute(array('id' => $comment_id));

		$this->assertSame('', get_comment_meta($comment_id, '_wp_trash_meta_status', true));
		$this->assertSame('', get_comment_meta($comment_id, '_wp_trash_meta_time', true));
	}

	public function test_output_shape_is_flat_and_closed(): void {
		$this->actingAs('administrator');
		$comment_id = $this->makeTrashedComment('1');

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $comment_id));

		$this->assertIsArray($result);
		$this->assertSame(array('id', 'status', 'previous_status'), array_keys($result));
	}

	/**
	 * Wrong-state guard: a comment that is not in the trash is rejected with a 409
	 * `rest_comment_wrong_state` error and left untouched. Core wp_untrash_comment()
	 * has no in-trash guard and would otherwise force the comment to its (empty) meta
	 * status — a silent wrong mutation. Mirrors the B5/L13 convention.
	 */
	public function test_untrashing_non_trashed_comment_returns_409_and_leaves_status_unchanged(): void {
		$this->actingAs('administrator');

		$approved_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'An approved, non-trashed comment.',
				'comment_approved' => '1',
			)
		);

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $approved_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_wrong_state', $result->get_error_code());
		$this->assertSame(409, $result->get_error_data()['status']);
		$this->assertSame('approved', wp_get_comment_status($approved_id));
	}

	/**
	 * A logged-out caller is denied by the coarse permission gate. The Abilities API
	 * collapses the non-true permission_callback into its generic error.
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);
		$comment_id = $this->makeTrashedComment('1');

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	/**
	 * A logged-in user without edit permission on the comment gets a specific
	 * `rest_cannot_edit` 403 from execute(), not the generic gate failure (backlog B4).
	 */
	public function test_non_moderator_is_denied_with_403(): void {
		$comment_id = $this->makeTrashedComment('1');
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_edit', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
		$this->assertSame('trash', wp_get_comment_status($comment_id));
	}

	/**
	 * The capability check (403) runs before the wrong-state check (409): an
	 * unauthorized caller targeting a non-trashed comment is denied with 403, never
	 * given a state hint.
	 */
	public function test_non_moderator_denied_before_wrong_state_check(): void {
		$approved_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'An approved, non-trashed comment.',
				'comment_approved' => '1',
			)
		);
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => $approved_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_edit', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
	}

	/**
	 * A moderator passing a non-existent comment id gets a clean 404 WP_Error, ordered
	 * before the capability check.
	 */
	public function test_missing_comment_id_returns_404(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/untrash-comment')->execute(array('id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}
}
