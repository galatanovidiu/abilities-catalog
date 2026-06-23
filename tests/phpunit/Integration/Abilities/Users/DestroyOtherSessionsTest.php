<?php
/**
 * Integration tests for the users/destroy-other-sessions ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Session_Tokens;

/**
 * Exercises users/destroy-other-sessions end-to-end: it ends the current user's
 * other sessions while keeping the caller's own, rejects a non-cookie context,
 * and denies a logged-out caller.
 */
final class DestroyOtherSessionsTest extends TestCase {

	/**
	 * The session token kept for the current user (set as the logged_in cookie).
	 *
	 * @var string
	 */
	private string $kept_token = '';

	public function tear_down(): void {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		parent::tear_down();
	}

	/**
	 * Seeds a session for the current user and makes it the active (cookie) one.
	 *
	 * Sets the logged_in cookie so `wp_get_session_token()` resolves to the kept
	 * token, mirroring an interactive cookie login under PHPUnit.
	 *
	 * @param int $user_id The user to log in.
	 * @return string The kept session token.
	 */
	private function loginWithSession( int $user_id ): string {
		$expiration                  = time() + DAY_IN_SECONDS;
		$token                       = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

		return $token;
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'users/destroy-other-sessions' ) );
	}

	public function test_ends_other_sessions_and_keeps_the_current_one(): void {
		$user_id          = $this->actingAs( 'administrator' );
		$this->kept_token = $this->loginWithSession( $user_id );

		// Seed two additional sessions on other "devices".
		$manager = WP_Session_Tokens::get_instance( $user_id );
		$other_a = $manager->create( time() + DAY_IN_SECONDS );
		$other_b = $manager->create( time() + DAY_IN_SECONDS );

		$this->assertCount( 3, $manager->get_all() );

		$result = wp_get_ability( 'users/destroy-other-sessions' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'destroyed', 'remaining' ), array_keys( $result ) );
		$this->assertTrue( $result['destroyed'] );
		$this->assertSame( 1, $result['remaining'] );

		// The kept session survives; the others are gone.
		$fresh = WP_Session_Tokens::get_instance( $user_id );
		$this->assertNotNull( $fresh->get( $this->kept_token ) );
		$this->assertNull( $fresh->get( $other_a ) );
		$this->assertNull( $fresh->get( $other_b ) );
	}

	public function test_no_cookie_session_returns_no_session_400(): void {
		// Logged in but with NO logged_in cookie (e.g. an application-password request).
		$this->actingAs( 'administrator' );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$result = wp_get_ability( 'users/destroy-other-sessions' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_no_session', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'users/destroy-other-sessions' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
