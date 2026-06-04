<?php
/**
 * Integration tests for the comments/list-comments ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the list read end-to-end: real comments in, shaped collection plus
 * totals out, with the capability guard enforced by the Abilities API on
 * execute(). Also guards the `view`-default context so a baseline `edit_posts`
 * user (Author, no `moderate_comments`) is not hard-rejected by core.
 */
final class ListCommentsTest extends TestCase {

	/**
	 * Post the comments are attached to.
	 *
	 * @var int
	 */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
		self::factory()->comment->create_many(
			3,
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => '1',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('comments/list-comments');

		$this->assertNotNull($ability);
		$this->assertSame('comments/list-comments', $ability->get_name());
	}

	public function test_admin_lists_comments_with_totals(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/list-comments')->execute(array('post' => array($this->post_id)));

		$this->assertIsArray($result);
		$this->assertArrayHasKey('items', $result);
		$this->assertCount(3, $result['items']);
		$this->assertSame(3, $result['total']);
		$this->assertSame(1, $result['total_pages']);
	}

	public function test_baseline_user_can_list_with_default_context(): void {
		// Author has edit_posts but not moderate_comments. The view-default
		// context must not trigger core's rest_forbidden_context (403).
		$this->actingAs('author');

		$result = wp_get_ability('comments/list-comments')->execute(array('post' => array($this->post_id)));

		$this->assertIsArray($result);
		$this->assertCount(3, $result['items']);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('comments/list-comments')->execute(array());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}
}
