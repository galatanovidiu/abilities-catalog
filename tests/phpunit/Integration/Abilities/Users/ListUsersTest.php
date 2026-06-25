<?php
/**
 * Integration tests for the og-users/list-users ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the list read end-to-end: real users in, shaped collection plus
 * totals out, with the capability guard enforced by the Abilities API on
 * execute(). Asserts the closed view-context field set and that edit-context-only
 * fields (username, email, roles) are present only when core serves them in edit
 * context — mirroring the ListComments moderator/non-moderator split.
 */
final class ListUsersTest extends TestCase {

	/**
	 * A known user with a distinctive profile so shaped values can be asserted.
	 *
	 * @var int
	 */
	private int $known_user_id;

	public function set_up(): void {
		parent::set_up();

		$this->known_user_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Known Person',
				'user_email'   => 'known@example.com',
				'user_url'     => 'https://known.example.com/',
				'description'  => 'A known editor.',
			)
		);
	}

	/**
	 * Finds a shaped row by user id in the result items.
	 *
	 * @param array<int,array<string,mixed>> $items The shaped result rows.
	 * @param int                            $id    The user id to find.
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
		$ability = wp_get_ability('og-users/list-users');

		$this->assertNotNull($ability);
		$this->assertSame('og-users/list-users', $ability->get_name());
	}

	public function test_admin_lists_users_with_totals(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/list-users')->execute(array('per_page' => 100));

		$this->assertIsArray($result);
		$this->assertArrayHasKey('items', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('total_pages', $result);
		$this->assertNotNull($this->rowById($result['items'], $this->known_user_id));
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('og-users/list-users')->execute(array());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_view_context_row_has_exactly_the_closed_view_field_set(): void {
		// Default (view) context: core strips edit-only fields by the request
		// context, so the row must carry exactly the always-present set even for
		// an administrator.
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/list-users')->execute(array('per_page' => 100));
		$row    = $this->rowById($result['items'], $this->known_user_id);
		$this->assertNotNull($row, 'The known user must appear in the list.');

		$expected = array(
			'id',
			'name',
			'url',
			'description',
			'slug',
		);
		sort($expected);
		$actual = array_keys($row);
		sort($actual);

		$this->assertSame($expected, $actual, 'View-context row must carry exactly the closed view field set.');
		$this->assertArrayNotHasKey('email', $row, 'Edit-only email must not leak in view context.');
		$this->assertArrayNotHasKey('roles', $row, 'Edit-only roles must not leak in view context.');
		$this->assertArrayNotHasKey('username', $row, 'Edit-only username must not leak in view context.');
	}

	public function test_view_context_row_values_match_source_user(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/list-users')->execute(array('per_page' => 100));
		$row    = $this->rowById($result['items'], $this->known_user_id);

		$this->assertNotNull($row);
		$this->assertSame($this->known_user_id, $row['id']);
		$this->assertSame('Known Person', $row['name']);
		$this->assertSame('https://known.example.com/', $row['url']);
		$this->assertSame('A known editor.', $row['description']);
		$this->assertNotSame('', $row['slug']);
	}

	public function test_edit_context_exposes_gated_fields_to_admin(): void {
		// Administrator has list_users + edit_users: edit context returns username,
		// email, and roles, and the shaper must surface them.
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/list-users')->execute(
			array(
				'per_page' => 100,
				'context'  => 'edit',
			)
		);
		$row = $this->rowById($result['items'], $this->known_user_id);

		$this->assertNotNull($row);
		$this->assertArrayHasKey('email', $row, 'Edit context must expose email to a privileged user.');
		$this->assertSame('known@example.com', $row['email']);
		$this->assertArrayHasKey('username', $row, 'Edit context must expose username to a privileged user.');
		$this->assertArrayHasKey('roles', $row, 'Edit context must expose roles to a privileged user.');
		$this->assertContains('editor', $row['roles']);
	}
}
