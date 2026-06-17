<?php
/**
 * Integration tests for the users/delete-application-password ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Application_Passwords;
use WP_Error;

/**
 * Exercises the destructive revoke end-to-end: an existing application password in,
 * a deletion result carrying a snapshot of the revoked credential out, with the
 * delete_app_password guard enforced by the Abilities API on execute(). Locks the
 * output shape (name and app_id, not just deleted + uuid) so an agent can confirm
 * which credential was revoked, and proves an unknown uuid surfaces core's typed
 * not-found error unchanged.
 */
final class DeleteApplicationPasswordTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The REST delete route gates on wp_is_application_passwords_available(),
		// which is false without HTTPS in the test runner. Force it on so the
		// wrapped route reaches the credential lookup.
		add_filter('wp_is_application_passwords_available', '__return_true');
	}

	public function tear_down(): void {
		remove_filter('wp_is_application_passwords_available', '__return_true');
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('users/delete-application-password');

		$this->assertNotNull($ability);
		$this->assertSame('users/delete-application-password', $ability->get_name());
	}

	public function test_admin_revokes_password_and_returns_credential_snapshot(): void {
		$user_id = $this->actingAs('administrator');

		[, $item] = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => 'Laptop CLI',
				'app_id' => 'b8e7c2a0-0000-4000-8000-000000000001',
			)
		);

		$result = wp_get_ability('users/delete-application-password')->execute(
			array(
				'user_id' => $user_id,
				'uuid'    => $item['uuid'],
			)
		);

		$this->assertIsArray($result);
		$this->assertTrue($result['deleted'], 'The credential must be reported as revoked.');
		$this->assertSame($item['uuid'], $result['uuid']);
		$this->assertSame('Laptop CLI', $result['name'], 'The revoked credential name must be returned.');
		$this->assertSame('b8e7c2a0-0000-4000-8000-000000000001', $result['app_id']);

		$this->assertNull(
			WP_Application_Passwords::get_user_application_password($user_id, $item['uuid']),
			'The application password must be gone from storage after revoke.'
		);
	}

	public function test_output_carries_the_closed_shape(): void {
		$user_id = $this->actingAs('administrator');

		[, $item] = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array('name' => 'No App ID')
		);

		$result = wp_get_ability('users/delete-application-password')->execute(
			array(
				'user_id' => $user_id,
				'uuid'    => $item['uuid'],
			)
		);

		$this->assertIsArray($result);
		$expected = array('deleted', 'uuid', 'name', 'app_id', 'created', 'last_used');
		sort($expected);
		$actual = array_keys($result);
		sort($actual);
		$this->assertSame($expected, $actual, 'Output must carry exactly the documented closed shape.');

		$this->assertSame('', $result['app_id'], 'A credential without an app_id must yield an empty string.');
		$this->assertNull($result['last_used'], 'A never-used credential must report last_used as null.');
	}

	public function test_unknown_uuid_returns_core_not_found_error(): void {
		$user_id = $this->actingAs('administrator');

		$result = wp_get_ability('users/delete-application-password')->execute(
			array(
				'user_id' => $user_id,
				'uuid'    => 'ffffffff-0000-4000-8000-000000000000',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_application_password_not_found', $result->get_error_code());

		$data = $result->get_error_data();
		$this->assertIsArray($data);
		$this->assertSame(404, $data['status']);
	}

	public function test_wrong_capability_surfaces_specific_403_and_credential_survives(): void {
		// An author lacks edit_user on another user's credential. After coarsening, the
		// logged-in floor passes and the wrapped route's own delete guard denies the
		// revoke with its specific 403 — the credential is untouched, proving the
		// object-level guard still holds.
		$owner_id = self::factory()->user->create(array('role' => 'administrator'));

		[, $item] = WP_Application_Passwords::create_new_application_password(
			$owner_id,
			array('name' => 'Owned By Admin')
		);

		$this->actingAs('author');

		$result = wp_get_ability('users/delete-application-password')->execute(
			array(
				'user_id' => $owner_id,
				'uuid'    => $item['uuid'],
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_cannot_delete_application_password', $result->get_error_code());
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());

		$data = $result->get_error_data();
		$this->assertIsArray($data);
		$this->assertSame(403, $data['status']);

		$this->assertNotNull(
			WP_Application_Passwords::get_user_application_password($owner_id, $item['uuid']),
			'The credential must survive a denied revoke.'
		);
	}
}
