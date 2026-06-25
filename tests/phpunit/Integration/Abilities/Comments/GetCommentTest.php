<?php
/**
 * Integration tests for the og-comments/get-comment ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises a representative read ability end-to-end: real WordPress data in,
 * shaped field set out, with the capability guard enforced by the Abilities
 * API on execute().
 */
final class GetCommentTest extends TestCase {

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
				'comment_post_ID' => $this->post_id,
				'comment_content' => 'A test comment body.',
				'comment_approved' => '1',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-comments/get-comment');

		$this->assertNotNull($ability);
		$this->assertSame('og-comments/get-comment', $ability->get_name());
	}

	public function test_admin_can_read_comment_fields(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-comments/get-comment')->execute(array('id' => $this->comment_id));

		$this->assertIsArray($result);
		$this->assertSame($this->comment_id, $result['id']);
		$this->assertSame($this->post_id, $result['post']);
		$this->assertStringContainsString('A test comment body.', $result['content']);
		$this->assertSame('approved', $result['status']);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('og-comments/get-comment')->execute(array('id' => $this->comment_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}
}
