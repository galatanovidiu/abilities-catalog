<?php
/**
 * Integration tests for the og-users/get-user ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single-user read end-to-end: a real user in, a flat shaped
 * record out, with the capability guard enforced by the Abilities API on
 * execute(). Asserts the closed view-context field set, the missing-object 404,
 * and that edit-context-only fields (email, roles) appear only when core serves
 * them in edit context.
 */
final class GetUserTest extends TestCase {

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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-users/get-user');

		$this->assertNotNull($ability);
		$this->assertSame('og-users/get-user', $ability->get_name());
	}

	public function test_admin_gets_user_in_view_context(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user')->execute(array('id' => $this->known_user_id));

		$this->assertIsArray($result);
		$this->assertSame($this->known_user_id, $result['id']);
		$this->assertSame('Known Person', $result['name']);
		$this->assertSame('https://known.example.com/', $result['url']);
		$this->assertSame('A known editor.', $result['description']);
		$this->assertNotSame('', $result['slug']);
	}

	public function test_view_context_record_has_exactly_the_closed_view_field_set(): void {
		// Default (view) context: core strips edit-only fields by the request
		// context, so the record must carry exactly the always-present set even
		// for an administrator.
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user')->execute(array('id' => $this->known_user_id));

		$expected = array(
			'id',
			'name',
			'url',
			'description',
			'slug',
		);
		sort($expected);
		$actual = array_keys($result);
		sort($actual);

		$this->assertSame($expected, $actual, 'View-context record must carry exactly the closed view field set.');
		$this->assertArrayNotHasKey('email', $result, 'Edit-only email must not leak in view context.');
		$this->assertArrayNotHasKey('roles', $result, 'Edit-only roles must not leak in view context.');
		$this->assertArrayNotHasKey('capabilities', $result, 'Edit-only capabilities must not leak in view context.');
		$this->assertArrayNotHasKey('registered_date', $result, 'Edit-only registered_date must not leak in view context.');
	}

	public function test_edit_context_exposes_gated_fields_to_admin(): void {
		// Administrator has list_users + edit_users: edit context returns email
		// and roles, and the shaper must surface them.
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user')->execute(
			array(
				'id'      => $this->known_user_id,
				'context' => 'edit',
			)
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('email', $result, 'Edit context must expose email to a privileged user.');
		$this->assertSame('known@example.com', $result['email']);
		$this->assertArrayHasKey('roles', $result, 'Edit context must expose roles to a privileged user.');
		$this->assertContains('editor', $result['roles']);
	}

	public function test_missing_user_returns_specific_404_not_permission_denial(): void {
		$this->actingAs('administrator');

		$missing_id = $this->known_user_id + 100000;

		$result = wp_get_ability('og-users/get-user')->execute(array('id' => $missing_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_user_invalid_id', $result->get_error_code());
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_logged_out_view_of_postless_user_surfaces_specific_403(): void {
		// After coarsening, the wrapped route's own guard runs. A logged-out caller
		// viewing a user with no published posts is still denied (the guard is not
		// weakened), but now with the route's specific authorization error instead of
		// the generic permission collapse.
		wp_set_current_user(0);

		$result = wp_get_ability('og-users/get-user')->execute(array('id' => $this->known_user_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_user_cannot_view', $result->get_error_code());
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());

		$data = $result->get_error_data();
		$this->assertIsArray($data);
		$this->assertSame(401, $data['status']);
	}

	public function test_edit_context_on_another_user_without_cap_surfaces_specific_403(): void {
		// GetUser delegates the object gate to the route. A low-privilege caller asking
		// for edit context on another user must be denied with the route's specific 403
		// (the kept object-level edit_user gate), not the generic permission collapse —
		// and no edit-only field may leak. This locks the guard the coarsening relies on.
		$subscriber = self::factory()->user->create(array('role' => 'subscriber'));
		wp_set_current_user($subscriber);

		$result = wp_get_ability('og-users/get-user')->execute(
			array(
				'id'      => $this->known_user_id,
				'context' => 'edit',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_forbidden_context', $result->get_error_code());
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());

		$data = $result->get_error_data();
		$this->assertIsArray($data);
		$this->assertSame(403, $data['status']);
	}

	public function test_self_view_without_list_users_is_allowed(): void {
		// A subscriber lacks list_users, but core always lets a user read their own
		// profile in view context. The old list_users-only guard was stricter than
		// core; coarsening to the route restores the self-read to match core.
		$subscriber = self::factory()->user->create(array('role' => 'subscriber'));
		wp_set_current_user($subscriber);

		$this->assertFalse(current_user_can('list_users'), 'Test premise: a subscriber lacks list_users.');

		$result = wp_get_ability('og-users/get-user')->execute(array('id' => $subscriber));

		$this->assertIsArray($result, 'A user must be able to read their own profile in view context.');
		$this->assertSame($subscriber, $result['id']);
	}
}
