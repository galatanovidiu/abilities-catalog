<?php
/**
 * Integration tests for the comments/create-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the create write ability end-to-end: a comment is created on a
 * post, the output shape carries the additive edit_link, and the capability
 * guard is enforced on execute().
 */
final class CreateCommentTest extends TestCase {

	/**
	 * Post the comment is attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/create-comment');

		$this->assertNotNull($ability);
		$this->assertSame('comments/create-comment', $ability->get_name());
	}

	public function test_admin_can_create_comment(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'    => $this->post_id,
				'content' => 'A freshly created comment.',
			)
		);

		$this->assertIsArray($result);
		$this->assertGreaterThan(0, $result['id']);
		$this->assertNotSame('', $result['status']);
	}

	public function test_output_includes_edit_link(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'    => $this->post_id,
				'content' => 'Comment with edit link.',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame(array('id', 'status', 'link', 'edit_link'), array_keys($result));
		$this->assertStringContainsString('comment.php', $result['edit_link']);
		$this->assertStringContainsString('action=editcomment', $result['edit_link']);
		$this->assertStringContainsString((string) $result['id'], $result['edit_link']);
	}

	public function test_status_enum_rejects_unknown_value(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'    => $this->post_id,
				'content' => 'Bad status value.',
				'status'  => 'bogus',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'    => $this->post_id,
				'content' => 'Should be denied.',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}
}
