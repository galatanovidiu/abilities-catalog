<?php
/**
 * Integration tests for the users/create-application-password ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the create end-to-end: a name in, a new credential out carrying the
 * one-time plaintext password. Proves the logged-in floor lets a user create their
 * own credential (never stricter than core), that creating for another user without
 * the capability surfaces the wrapped route's specific 403 rather than the generic
 * permission collapse, and that a logged-out caller is denied.
 */
final class CreateApplicationPasswordTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The REST create route gates on wp_is_application_passwords_available(),
		// which is false without HTTPS in the test runner. Force it on so the wrapped
		// route reaches the credential creation.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'users/create-application-password' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'users/create-application-password', $ability->get_name() );
	}

	public function test_admin_creates_own_app_password_and_returns_plaintext_once(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/create-application-password' )->execute(
			array( 'name' => 'Laptop CLI' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Laptop CLI', $result['name'] );
		$this->assertNotSame( '', $result['uuid'] );
		$this->assertNotSame( '', $result['password'], 'The one-time plaintext password must be returned.' );
	}

	public function test_subscriber_creates_own_app_password(): void {
		// A subscriber may create an application password for themselves. The logged-in
		// floor must let this through so the ability is never stricter than core.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'users/create-application-password' )->execute(
			array( 'name' => 'Phone App' )
		);

		$this->assertIsArray( $result, 'A subscriber must be able to create their own application password.' );
		$this->assertSame( 'Phone App', $result['name'] );
		$this->assertNotSame( '', $result['password'] );
	}

	public function test_creating_for_another_user_without_cap_surfaces_specific_403(): void {
		// A subscriber may not create an application password for another user. After
		// coarsening, the logged-in floor passes and the wrapped route's own guard
		// denies with its specific 403 instead of the generic permission collapse.
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'users/create-application-password' )->execute(
			array(
				'user_id' => $owner_id,
				'name'    => 'Stolen',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create_application_passwords', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'users/create-application-password' )->execute(
			array( 'name' => 'Nope' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
