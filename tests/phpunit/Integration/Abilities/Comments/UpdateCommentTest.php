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

	/**
	 * B6 regression: a malformed author_email must reach core, which validates it
	 * via check_comment_author_email and returns rest_invalid_email (400). The
	 * ability must NOT pre-sanitize the value to '' and silently drop it.
	 */
	public function test_malformed_author_email_surfaces_core_validation_error(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'           => $this->comment_id,
				'author_email' => 'not-an-email',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		// Core wraps the per-param failure in `rest_invalid_param` and nests the
		// specific `rest_invalid_email` code under error_data details.
		$this->assertSame('rest_invalid_param', $result->get_error_code());
		$data = $result->get_error_data();
		$this->assertSame(400, $data['status']);
		$this->assertSame('rest_invalid_email', $data['details']['author_email']['code']);
		$this->assertSame(
			'',
			get_comment($this->comment_id)->comment_author_email,
			'The stored email should be unchanged when core rejects the new value.'
		);
	}

	/**
	 * Companion to the malformed-email case: a valid author_email updates the
	 * stored comment.
	 */
	public function test_valid_author_email_is_stored(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'           => $this->comment_id,
				'author_email' => 'new.email@example.com',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('new.email@example.com', $result['author_email']);
		$this->assertSame(
			'new.email@example.com',
			get_comment($this->comment_id)->comment_author_email
		);
	}

	/**
	 * B7 regression: an explicit empty `author_name` is a "blank this field"
	 * intent, not an omission. Core forwards it (isset gate) and stores the empty
	 * value, so the ability must forward it too rather than dropping it via a
	 * `'' !==` guard.
	 */
	public function test_explicit_empty_author_name_blanks_the_stored_value(): void {
		$this->actingAs('administrator');

		// Seed a non-empty author so a blank is observable.
		wp_update_comment(
			array(
				'comment_ID'     => $this->comment_id,
				'comment_author' => 'Seeded Author',
			)
		);

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'          => $this->comment_id,
				'author_name' => '',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('', $result['author_name']);
		$this->assertSame(
			'',
			get_comment($this->comment_id)->comment_author,
			'An explicit empty author_name must blank the stored author, not be dropped.'
		);
	}

	/**
	 * B7 regression: an explicit empty `author_email` blanks the stored email.
	 * Core forwards the empty string (isset gate) and stores it.
	 */
	public function test_explicit_empty_author_email_blanks_the_stored_value(): void {
		$this->actingAs('administrator');

		wp_update_comment(
			array(
				'comment_ID'           => $this->comment_id,
				'comment_author_email' => 'seed@example.com',
			)
		);

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'           => $this->comment_id,
				'author_email' => '',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('', $result['author_email']);
		$this->assertSame(
			'',
			get_comment($this->comment_id)->comment_author_email,
			'An explicit empty author_email must blank the stored email, not be dropped.'
		);
	}

	/**
	 * B7 regression: an explicit empty `content` must reach core, which rejects it
	 * with a 400 `rest_comment_content_invalid`. The ability must NOT drop the
	 * empty value via a `'' !==` guard and silently succeed as a no-op, which
	 * would hide core's validation error.
	 */
	public function test_explicit_empty_content_surfaces_core_validation_error(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => $this->comment_id,
				'content' => '',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_content_invalid', $result->get_error_code());
		$this->assertSame(400, $result->get_error_data()['status']);
		$this->assertSame(
			'Original comment body.',
			get_comment($this->comment_id)->comment_content,
			'The stored content must be unchanged when core rejects an empty value.'
		);
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

	/**
	 * A logged-in non-moderator gets the wrapped route's specific `rest_cannot_edit`
	 * 403, not the generic gate failure (backlog B4).
	 */
	public function test_non_moderator_is_denied_with_403(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => $this->comment_id,
				'content' => 'Should be denied.',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_edit', $result->get_error_code());
		$this->assertSame(403, $result->get_error_data()['status']);
	}

	/**
	 * B4 regression: a non-moderator passing a missing comment id receives the
	 * wrapped route's specific 404, not a generic permission failure.
	 */
	public function test_non_moderator_missing_id_returns_404_not_generic(): void {
		$this->actingAs('subscriber');

		$result = wp_get_ability('comments/update-comment')->execute(
			array(
				'id'      => 99999999,
				'content' => 'Should 404.',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_comment_invalid_id', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status']);
	}
}
