<?php
/**
 * Integration tests for the comments/approve-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises a representative write ability end-to-end: a held comment is
 * approved, the capability guard is enforced on execute(), and the current
 * core no-op behavior (re-approving) is documented.
 */
final class ApproveCommentTest extends TestCase {

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
		$ability = wp_get_ability('comments/approve-comment');

		$this->assertNotNull($ability);
		$this->assertSame('comments/approve-comment', $ability->get_name());
	}

	public function test_admin_can_approve_held_comment(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame($this->comment_id, $result['id']);
		$this->assertSame('approved', $result['status']);
	}

	public function test_output_shape_has_only_id_and_status(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame(array('id', 'status'), array_keys($result));
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	/**
	 * A logged-in user without edit permission on the comment gets a specific
	 * `rest_cannot_edit` 403 from execute(), not the generic gate failure. The
	 * object-level capability moved out of permission_callback (backlog B4).
	 */
	public function test_non_moderator_is_denied_with_403(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_edit', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
	}

	/**
	 * B4 regression: a non-moderator passing a missing comment id receives the
	 * specific 404, not a generic permission failure. The id-validity check
	 * (404) is ordered before the capability check (403) in execute().
	 */
	public function test_non_moderator_missing_id_returns_404_not_generic(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}

	/**
	 * The 403 fires even when the status change would be a no-op: an unauthorized
	 * user targeting an already-approved comment is denied, proving the capability
	 * check sits before the no-op skip branch (no information leak, no mutation).
	 */
	public function test_non_moderator_denied_on_already_approved_noop(): void {
		$approved_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Already approved.',
				'comment_approved' => '1',
			)
		);

		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $approved_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_edit', $result->get_error_code());
	}

	/**
	 * The execute() guard mirrors core check_edit_permission (moderate_comments OR
	 * edit_comment). A user holding `moderate_comments` but without post-edit caps
	 * (so `edit_comment`, which maps to `edit_post`, is false) must still be allowed.
	 * Guards against regressing a moderator who cannot edit the parent post — e.g. an
	 * orphaned comment whose post is gone.
	 */
	public function test_moderate_comments_only_user_can_approve(): void {
		$user_id = self::factory()->user->create(array('role' => 'subscriber'));
		get_user_by('id', $user_id)->add_cap('moderate_comments');
		wp_set_current_user($user_id);

		$this->assertFalse(current_user_can('edit_comment', $this->comment_id));

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame('approved', $result['status']);
	}

	/**
	 * An already-approved comment is a no-op: the ability skips the status change
	 * (re-applying it returns false from wp_set_comment_status) and reports the
	 * existing `approved` status rather than an error.
	 */
	public function test_reapproving_already_approved_comment_keeps_approved_status(): void {
		$this->actingAs('administrator');

		$approved_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_content'  => 'Already approved.',
				'comment_approved' => '1',
			)
		);

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $approved_id));

		$this->assertIsArray($result);
		$this->assertSame('approved', $result['status']);
	}

	/**
	 * Regression guard for B3: approving must not rewrite the stored commenter IP.
	 * The status change is the only intended mutation; in a non-browser run the old
	 * REST dispatch overwrote `comment_author_IP` with the empty/`127.0.0.1`
	 * REMOTE_ADDR. The stored IP must survive the status change byte-for-byte.
	 */
	public function test_approving_preserves_comment_author_ip(): void {
		$this->actingAs('administrator');

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'   => $this->post_id,
				'comment_content'   => 'Held comment with a recorded IP.',
				'comment_approved'  => '0',
				'comment_author_IP' => '203.0.113.45',
			)
		);

		wp_get_ability('comments/approve-comment')->execute(array('id' => $comment_id));

		$this->assertSame('203.0.113.45', get_comment($comment_id)->comment_author_IP);
	}

	/**
	 * A moderator passing a non-existent comment id gets a clean 404 WP_Error,
	 * not a PHP warning or an action on an aliased id. Representative for the four
	 * status abilities, which share the same id-validity + existence guard.
	 */
	public function test_missing_comment_id_returns_404(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}
}
