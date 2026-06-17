<?php
/**
 * Integration tests for the users/update-current-user ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the self-update end-to-end: validated input in, the current user's flat
 * shape (id/name/first_name/last_name/email/url/locale/link/roles, never the
 * password) out. Proves the accepted profile fields are echoed back, that a denied
 * caller is blocked before the REST route runs, and that the error path stays
 * redacted while keeping a stable code and status.
 */
final class UpdateCurrentUserTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'users/update-current-user' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'users/update-current-user', $ability->get_name() );
	}

	public function test_admin_updates_own_profile_with_flat_shape_and_no_password(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/update-current-user' )->execute(
			array(
				'name'       => 'Edited Admin',
				'first_name' => 'Edited',
				'last_name'  => 'Admin',
				'url'        => 'https://example.com',
				'password'   => 'Rotated-Pass-Word!',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'name', 'first_name', 'last_name', 'email', 'url', 'locale', 'link', 'roles' ),
			array_keys( $result ),
			'Output must be the flat shape and must not include the password.'
		);
		$this->assertArrayNotHasKey( 'password', $result );
		$this->assertSame( get_current_user_id(), $result['id'] );
		$this->assertSame( 'Edited Admin', $result['name'] );
		$this->assertSame( 'Edited', $result['first_name'] );
		$this->assertSame( 'Admin', $result['last_name'] );
		$this->assertSame( 'https://example.com', $result['url'] );
		$this->assertContains( 'administrator', $result['roles'] );
	}

	public function test_wrong_capability_is_denied(): void {
		// A logged-out caller has no current user; the Abilities API blocks execute()
		// before the REST route runs.
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'users/update-current-user' )->execute(
			array(
				'name' => 'Should Not Apply',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_self_role_change_without_promote_is_denied(): void {
		// A subscriber cannot promote themselves. The kept promote_user guard denies a
		// self roles change up front, and the subscriber's role is unchanged —
		// coarsening the always-true edit_user(self) mirror must not open
		// self-escalation. Asserting the generic collapse locks the guard in place
		// here (dropping it would instead surface the route's rest_cannot_edit_roles).
		$actor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $actor );

		$this->assertFalse( current_user_can( 'promote_user', $actor ), 'Test premise: a subscriber cannot promote.' );

		$result = wp_get_ability( 'users/update-current-user' )->execute(
			array( 'roles' => array( 'administrator' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$user = get_userdata( $actor );
		$this->assertNotContains( 'administrator', array_values( $user->roles ), 'A denied self role change must not promote the user.' );
		$this->assertContains( 'subscriber', array_values( $user->roles ) );
	}

	public function test_duplicate_email_returns_redacted_error_with_stable_code(): void {
		// Another user already owns the target email. Core rejects the change. The
		// ability must surface a redacted WP_Error that still carries the original
		// stable code and an HTTP status, and must never echo the submitted password.
		$this->actingAs( 'administrator' );

		self::factory()->user->create(
			array(
				'user_login' => 'email_owner',
				'user_email' => 'taken@example.com',
			)
		);

		$result = wp_get_ability( 'users/update-current-user' )->execute(
			array(
				'email'    => 'taken@example.com',
				'password' => 'Must-Not-Leak-Pass!',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_email', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertIsInt( $data['status'] );

		// The redacted message is generic; the submitted password must not appear.
		$this->assertStringNotContainsString( 'Must-Not-Leak-Pass!', $result->get_error_message() );
	}
}
