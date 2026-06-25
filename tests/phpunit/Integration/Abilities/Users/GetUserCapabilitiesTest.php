<?php
/**
 * Integration tests for the og-users/get-user-capabilities ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the effective-capability read end-to-end: a real user in, a flat
 * shaped record (id, roles, capabilities) out, with the edit_users guard
 * enforced by the Abilities API on execute(). Asserts the resolved capability
 * set, role slugs, the closed output key-set, the missing-object 404, and the
 * denials for logged-out and under-privileged callers.
 */
final class GetUserCapabilitiesTest extends TestCase {

	/**
	 * An editor whose resolved capability set can be asserted.
	 *
	 * @var int
	 */
	private int $editor_id;

	public function set_up(): void {
		parent::set_up();

		$this->editor_id = self::factory()->user->create(array('role' => 'editor'));
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-users/get-user-capabilities');

		$this->assertNotNull($ability);
		$this->assertSame('og-users/get-user-capabilities', $ability->get_name());
	}

	public function test_admin_reads_editor_roles_and_capabilities(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user-capabilities')->execute(array('id' => $this->editor_id));

		$this->assertIsArray($result);
		$this->assertSame($this->editor_id, $result['id']);

		$this->assertIsArray($result['roles']);
		$this->assertContains('editor', $result['roles']);

		$this->assertIsArray($result['capabilities']);
		$this->assertContains('edit_posts', $result['capabilities']);

		// Read-back against core confirms the wrapped source of truth.
		$user = get_userdata($this->editor_id);
		$this->assertContains('editor', $user->roles);
		$this->assertNotEmpty($user->allcaps['edit_posts'] ?? null);
	}

	public function test_capabilities_are_sorted_and_exclude_role_slugs(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user-capabilities')->execute(array('id' => $this->editor_id));

		$sorted = $result['capabilities'];
		sort($sorted);
		$this->assertSame($sorted, $result['capabilities'], 'Capabilities must be returned sorted.');

		// Core mixes the role slug into allcaps as a true key; it must not appear
		// in the capability list (roles are surfaced separately).
		$this->assertNotContains('editor', $result['capabilities'], 'Role slugs must be filtered out of capabilities.');
	}

	public function test_output_has_exactly_the_closed_key_set(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user-capabilities')->execute(array('id' => $this->editor_id));

		$expected = array('id', 'roles', 'capabilities');
		sort($expected);
		$actual = array_keys($result);
		sort($actual);

		$this->assertSame($expected, $actual);
	}

	public function test_missing_user_returns_specific_404_not_permission_denial(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-users/get-user-capabilities')->execute(array('id' => 99999999));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_user_invalid_id', $result->get_error_code());
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());

		$data = $result->get_error_data();
		$this->assertIsArray($data);
		$this->assertSame(404, $data['status']);
	}

	public function test_logged_out_caller_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('og-users/get-user-capabilities')->execute(array('id' => $this->editor_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_subscriber_without_edit_users_is_denied(): void {
		$subscriber = self::factory()->user->create(array('role' => 'subscriber'));
		wp_set_current_user($subscriber);

		$this->assertFalse(current_user_can('edit_users'), 'Test premise: a subscriber lacks edit_users.');

		$result = wp_get_ability('og-users/get-user-capabilities')->execute(array('id' => $this->editor_id));

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}
}
