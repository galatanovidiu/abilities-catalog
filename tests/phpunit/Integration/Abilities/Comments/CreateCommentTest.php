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

	/**
	 * A logged-in non-moderator can still post an ordinary comment on a readable
	 * post. Relaxing the gate to is_user_logged_in() must not break legitimate use.
	 */
	public function test_non_moderator_can_create_plain_comment(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'    => $this->post_id,
				'content' => 'A subscriber comment.',
			)
		);

		$this->assertIsArray($result);
		$this->assertGreaterThan(0, $result['id']);
	}

	/**
	 * Security guard: the wrapped create route does NOT gate author_name on
	 * moderate_comments, so a non-moderator could otherwise spoof the stored author.
	 * This ability blocks it in execute() with a specific rest_comment_invalid_author
	 * 403, and no comment is created.
	 */
	public function test_non_moderator_cannot_spoof_author_name(): void {
		$this->actingAs('subscriber');

		$before = get_comments(array('post_id' => $this->post_id, 'count' => true));

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'        => $this->post_id,
				'content'     => 'Spoofed identity attempt.',
				'author_name' => 'Someone Else',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_author', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
		$this->assertSame(
			$before,
			get_comments(array('post_id' => $this->post_id, 'count' => true)),
			'No comment should be created when author spoofing is rejected.'
		);
	}

	/**
	 * Companion to the author_name guard: author_email is equally ungated by the
	 * wrapped route and must be blocked in execute() for a non-moderator.
	 */
	public function test_non_moderator_cannot_spoof_author_email(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'         => $this->post_id,
				'content'      => 'Spoofed email attempt.',
				'author_email' => 'victim@example.com',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_author', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
	}

	/**
	 * B4 win: a non-moderator setting `status` now reaches the wrapped route, which
	 * surfaces its specific `rest_comment_invalid_status` error instead of the
	 * Abilities API collapsing it into a generic permission failure.
	 */
	public function test_non_moderator_setting_status_gets_specific_route_error(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/create-comment')->execute(
			array(
				'post'    => $this->post_id,
				'content' => 'Trying to self-approve.',
				'status'  => 'approve',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_status', $result->get_error_code());
	}
}
