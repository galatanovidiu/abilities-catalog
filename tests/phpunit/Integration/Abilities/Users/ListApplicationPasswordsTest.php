<?php
/**
 * Integration tests for the og-users/list-application-passwords ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Application_Passwords;
use WP_Error;

/**
 * Exercises the read end-to-end: an admin lists a user's application passwords and
 * gets metadata rows back. Locks the closed output shape so the response carries
 * only the documented allowlist (uuid, name, created, last_used, last_ip) and never
 * a password field or any plaintext credential. Proves a non-existent user surfaces
 * core's typed error unchanged.
 */
final class ListApplicationPasswordsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The REST route gates on wp_is_application_passwords_available(), which is
		// false without HTTPS in the test runner. Force it on so the wrapped route
		// reaches the credential lookup.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-users/list-application-passwords' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-users/list-application-passwords', $ability->get_name() );
	}

	public function test_admin_lists_metadata_rows_for_a_user(): void {
		$user_id = $this->actingAs( 'administrator' );

		[, $item] = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'Laptop CLI' )
		);

		$result = wp_get_ability( 'og-users/list-application-passwords' )->execute(
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertCount( 1, $result['items'] );

		$row = $result['items'][0];
		$this->assertSame( $item['uuid'], $row['uuid'] );
		$this->assertSame( 'Laptop CLI', $row['name'] );
	}

	public function test_output_carries_the_closed_metadata_shape(): void {
		$user_id = $this->actingAs( 'administrator' );

		WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'Never Used' )
		);

		$result = wp_get_ability( 'og-users/list-application-passwords' )->execute(
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );

		$row      = $result['items'][0];
		$expected = array( 'uuid', 'name', 'created', 'last_used', 'last_ip' );
		sort( $expected );
		$actual = array_keys( $row );
		sort( $actual );
		$this->assertSame( $expected, $actual, 'A row must carry exactly the documented metadata allowlist.' );

		// No plaintext password and no opaque internals may ever leak.
		$this->assertArrayNotHasKey( 'password', $row );
		$this->assertArrayNotHasKey( 'new_password', $row );
		$this->assertArrayNotHasKey( '_links', $row );

		$this->assertNull( $row['last_used'], 'A never-used credential must report last_used as null.' );
	}

	public function test_missing_user_returns_core_typed_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-application-passwords' )->execute(
			array( 'user_id' => 999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 404, $data['status'] );
	}

	public function test_listing_another_users_passwords_without_cap_surfaces_specific_403(): void {
		// A subscriber may not list another user's application passwords. After
		// coarsening, the logged-in floor passes and the wrapped route's own guard
		// denies with its specific 403 instead of the generic permission collapse.
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		WP_Application_Passwords::create_new_application_password(
			$owner_id,
			array( 'name' => 'Owned By Admin' )
		);

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-users/list-application-passwords' )->execute(
			array( 'user_id' => $owner_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_list_application_passwords', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] );
	}
}
