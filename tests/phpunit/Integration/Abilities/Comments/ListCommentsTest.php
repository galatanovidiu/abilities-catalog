<?php
/**
 * Integration tests for the og-comments/list-comments ability.
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

	/**
	 * A known comment on the post, created with an explicit author email so the
	 * edit-context (moderation) field path is reachable in assertions.
	 *
	 * @var int
	 */
	private int $known_comment_id;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
		self::factory()->comment->create_many(
			2,
			array(
				'comment_post_ID'  => $this->post_id,
				'comment_approved' => '1',
			)
		);

		$this->known_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_approved'     => '1',
				'comment_author'       => 'Known Commenter',
				'comment_author_email' => 'known@example.com',
				'comment_author_url'   => 'https://known.example.com/',
				'comment_content'      => 'Hello from the known commenter.',
			)
		);
	}

	/**
	 * Finds a shaped row by comment id in the result items.
	 *
	 * @param array<int,array<string,mixed>> $items The shaped result rows.
	 * @param int                            $id    The comment id to find.
	 * @return array<string,mixed>|null The matching row or null.
	 */
	private function rowById(array $items, int $id): ?array {
		foreach ($items as $row) {
			if (isset($row['id']) && (int) $row['id'] === $id) {
				return $row;
			}
		}

		return null;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-comments/list-comments');

		$this->assertNotNull($ability);
		$this->assertSame('og-comments/list-comments', $ability->get_name());
	}

	public function test_admin_lists_comments_with_totals(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-comments/list-comments')->execute(array('post' => array($this->post_id)));

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

		$result = wp_get_ability('og-comments/list-comments')->execute(array('post' => array($this->post_id)));

		$this->assertIsArray($result);
		$this->assertCount(3, $result['items']);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('og-comments/list-comments')->execute(array());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_view_context_row_has_exactly_the_closed_view_field_set(): void {
		// Author has edit_posts but not moderate_comments: core serves the row in
		// view context, so moderation-only fields must be absent.
		$this->actingAs('author');

		$result = wp_get_ability('og-comments/list-comments')->execute(array('post' => array($this->post_id)));

		$row = $this->rowById($result['items'], $this->known_comment_id);
		$this->assertNotNull($row, 'The known comment must appear in the list.');

		$expected = array(
			'id',
			'post',
			'parent',
			'author',
			'author_name',
			'author_url',
			'date',
			'date_gmt',
			'link',
			'status',
			'type',
			'content',
		);
		sort($expected);
		$actual = array_keys($row);
		sort($actual);

		$this->assertSame($expected, $actual, 'View-context row must carry exactly the closed view field set.');
		$this->assertArrayNotHasKey('author_email', $row, 'Moderation-only author_email must not leak in view context.');
		$this->assertArrayNotHasKey('author_ip', $row, 'Moderation-only author_ip must not leak in view context.');
	}

	public function test_view_context_row_values_match_source_comment(): void {
		$this->actingAs('author');

		$result = wp_get_ability('og-comments/list-comments')->execute(array('post' => array($this->post_id)));
		$row    = $this->rowById($result['items'], $this->known_comment_id);

		$this->assertNotNull($row);
		$this->assertSame($this->known_comment_id, $row['id']);
		$this->assertSame($this->post_id, $row['post']);
		$this->assertSame('Known Commenter', $row['author_name']);
		$this->assertSame('https://known.example.com/', $row['author_url']);
		$this->assertSame('approved', $row['status']);
		$this->assertSame('comment', $row['type']);
		$this->assertStringContainsString('Hello from the known commenter.', $row['content']);
	}

	public function test_edit_context_exposes_moderation_fields_to_moderator(): void {
		// Administrator has moderate_comments: edit context returns author_email
		// and author_ip, and the shaper must surface them.
		$this->actingAs('administrator');

		$result = wp_get_ability('og-comments/list-comments')->execute(
			array(
				'post'    => array($this->post_id),
				'context' => 'edit',
			)
		);
		$row = $this->rowById($result['items'], $this->known_comment_id);

		$this->assertNotNull($row);
		$this->assertArrayHasKey('author_email', $row, 'Edit context must expose author_email to a moderator.');
		$this->assertSame('known@example.com', $row['author_email']);
		$this->assertArrayHasKey('author_ip', $row, 'Edit context must expose author_ip to a moderator.');
	}
}
