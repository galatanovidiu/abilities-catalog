<?php
/**
 * Integration tests for the users/create-user ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the create end-to-end: validated input in, the new user's flat shape
 * (id/username/email/roles, never the password) out, with the create_users guard
 * enforced by the Abilities API on execute(). Locks the roles forwarding so that
 * roles: [] yields a roleless user instead of the default role, and proves the
 * error path stays redacted while keeping a stable code and status.
 */
final class CreateUserTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('users/create-user');

		$this->assertNotNull($ability);
		$this->assertSame('users/create-user', $ability->get_name());
	}

	public function test_admin_creates_user_with_flat_shape_and_no_password(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('users/create-user')->execute(
			array(
				'username' => 'new_writer',
				'email'    => 'new_writer@example.com',
				'password' => 'S3cret-Pass-Word!',
				'roles'    => array('author'),
			)
		);

		$this->assertIsArray($result);
		$this->assertSame(
			array('id', 'username', 'email', 'roles'),
			array_keys($result),
			'Output must be the flat shape and must not include the password.'
		);
		$this->assertArrayNotHasKey('password', $result);
		$this->assertGreaterThan(0, $result['id']);
		$this->assertSame('new_writer', $result['username']);
		$this->assertSame('new_writer@example.com', $result['email']);
		$this->assertContains('author', $result['roles']);
	}

	public function test_empty_roles_creates_roleless_user(): void {
		// roles: [] must forward to core as an empty array so the new user has no
		// role, rather than falling back to the site default role.
		$this->actingAs('administrator');

		$result = wp_get_ability('users/create-user')->execute(
			array(
				'username' => 'roleless_user',
				'email'    => 'roleless@example.com',
				'password' => 'An0ther-Pass-Word!',
				'roles'    => array(),
			)
		);

		$this->assertIsArray($result);
		$this->assertSame(array(), $result['roles'], 'roles: [] must yield a roleless user.');

		$user = get_userdata($result['id']);
		$this->assertSame(array(), array_values($user->roles), 'The stored user must have no role.');
	}

	public function test_omitted_roles_assigns_default_role(): void {
		// Omitting roles entirely is distinct from roles: []; core then assigns the
		// site default role (subscriber on a fresh single-site install).
		$this->actingAs('administrator');

		$result = wp_get_ability('users/create-user')->execute(
			array(
				'username' => 'default_role_user',
				'email'    => 'default_role@example.com',
				'password' => 'Yet-An0ther-Pass!',
			)
		);

		$this->assertIsArray($result);
		$this->assertContains(get_option('default_role'), $result['roles']);
	}

	public function test_wrong_capability_is_denied(): void {
		// An author lacks create_users; the Abilities API blocks execute() before
		// the REST route runs.
		$this->actingAs('author');

		$result = wp_get_ability('users/create-user')->execute(
			array(
				'username' => 'should_not_exist',
				'email'    => 'should_not_exist@example.com',
				'password' => 'Blocked-Pass-Word!',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('ability_invalid_permissions', $result->get_error_code());
		$this->assertFalse(get_user_by('login', 'should_not_exist'), 'No user must be created when denied.');
	}

	public function test_duplicate_email_returns_redacted_error_with_stable_code(): void {
		// A second user with the same email is rejected by core. The ability must
		// surface a redacted WP_Error that still carries the original stable code
		// and an HTTP status, and must never echo the submitted password back.
		$this->actingAs('administrator');

		self::factory()->user->create(
			array(
				'user_login' => 'first_owner',
				'user_email' => 'taken@example.com',
			)
		);

		$result = wp_get_ability('users/create-user')->execute(
			array(
				'username' => 'second_owner',
				'email'    => 'taken@example.com',
				'password' => 'Must-Not-Leak-Pass!',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('existing_user_email', $result->get_error_code());

		$data = $result->get_error_data();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('status', $data);
		$this->assertIsInt($data['status']);

		// The redacted message is generic; the submitted password must not appear.
		$this->assertStringNotContainsString('Must-Not-Leak-Pass!', $result->get_error_message());
	}
}
