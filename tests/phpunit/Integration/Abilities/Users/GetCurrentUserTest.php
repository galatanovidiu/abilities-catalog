<?php
/**
 * Integration tests for the users/get-current-user ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the current-user read end-to-end: the logged-in user in, their own
 * shaped profile out, with the login guard enforced by the Abilities API on
 * execute(). Asserts the closed view-context field set (no email/roles leak) and
 * that edit-context surfaces the gated fields, including the capabilities map.
 */
final class GetCurrentUserTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('users/get-current-user');

		$this->assertNotNull($ability);
		$this->assertSame('users/get-current-user', $ability->get_name());
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user(0);

		$result = wp_get_ability('users/get-current-user')->execute(array());

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
	}

	public function test_view_context_returns_own_profile_for_logged_in_user(): void {
		$id = $this->actingAs('subscriber');

		$result = wp_get_ability('users/get-current-user')->execute(array());

		$this->assertIsArray($result);
		$this->assertSame($id, $result['id']);
		$this->assertNotSame('', $result['name']);
		$this->assertNotSame('', $result['slug']);
	}

	public function test_view_context_omits_edit_only_fields(): void {
		// Default (view) context: core strips email/roles/capabilities by request
		// context, even for the user's own /me record.
		$this->actingAs('subscriber');

		$result = wp_get_ability('users/get-current-user')->execute(array());

		$this->assertArrayNotHasKey('email', $result, 'Edit-only email must not leak in view context.');
		$this->assertArrayNotHasKey('roles', $result, 'Edit-only roles must not leak in view context.');
		$this->assertArrayNotHasKey('capabilities', $result, 'Edit-only capabilities must not leak in view context.');
		$this->assertArrayNotHasKey('registered_date', $result, 'Edit-only registered_date must not leak in view context.');
	}

	public function test_edit_context_exposes_gated_fields_with_capabilities_map(): void {
		$id = $this->actingAs('editor');

		$result = wp_get_ability('users/get-current-user')->execute(array('context' => 'edit'));

		$this->assertSame($id, $result['id']);
		$this->assertArrayHasKey('email', $result, 'Edit context must expose own email.');
		$this->assertArrayHasKey('roles', $result, 'Edit context must expose own roles.');
		$this->assertContains('editor', $result['roles']);
		$this->assertArrayHasKey('capabilities', $result, 'Edit context must expose own capabilities.');
		$this->assertIsArray($result['capabilities']);
		// capabilities is a map of capability-name => bool (matches the tightened schema).
		foreach ($result['capabilities'] as $granted) {
			$this->assertIsBool($granted, 'Each capability value must be a boolean.');
		}
	}
}
