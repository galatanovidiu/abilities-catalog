<?php
/**
 * Integration tests for the comments/update-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the update write ability end-to-end: an existing comment is
 * updated, the output shape carries the additive author/date/edit_link fields,
 * a non-positive id is rejected, and the capability guard is enforced.
 */
final class UpdateCommentTest extends TestCase {

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
				'comment_content'  => 'Original comment body.',
				'comment_approved' => '1',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/update-comment');

		$this->assertNotNull($ability);
		$this->assertSame('comments/update-comment', $ability->get_name());
	}

	public function test_admin_can_update_comment_content(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => $this->comment_id,
				'content' => 'Updated comment body.',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame($this->comment_id, $result['id']);
		$this->assertStringContainsString('Updated comment body.', $result['content']);
	}

	public function test_output_shape_carries_author_date_and_edit_link(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'          => $this->comment_id,
				'author_name' => 'Renamed Author',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame(
			array('id', 'content', 'status', 'author_name', 'author_email', 'date', 'edit_link'),
			array_keys($result)
		);
		$this->assertSame('Renamed Author', $result['author_name']);
		$this->assertStringContainsString('comment.php', $result['edit_link']);
		$this->assertStringContainsString('action=editcomment', $result['edit_link']);
		$this->assertStringContainsString((string) $result['id'], $result['edit_link']);
	}

	public function test_non_positive_id_is_rejected_by_schema(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => -17,
				'content' => 'Should never reach core.',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_input', $result->get_error_code());
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => $this->comment_id,
				'content' => 'Should be denied.',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_non_moderator_is_denied(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => $this->comment_id,
				'content' => 'Should be denied.',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}
}
