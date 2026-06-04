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

	public function test_non_moderator_is_denied(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/approve-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	/**
	 * Documents current behavior for an already-approved comment. The REST
	 * controller early-returns the unchanged comment, so the ability reports the
	 * existing `approved` status rather than an error. This asserts the present
	 * contract, not a desired one (see the deferred review item).
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
}
