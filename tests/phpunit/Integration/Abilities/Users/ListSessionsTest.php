<?php
/**
 * Integration tests for the og-users/list-sessions ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Session_Tokens;

/**
 * Exercises og-users/list-sessions end-to-end: seeding sessions via
 * WP_Session_Tokens, the default-current-user path, and the object-level
 * permission guard that keeps IP/user-agent rows readable only by self or an
 * editor.
 */
final class ListSessionsTest extends TestCase {

	/**
	 * Target user under test.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		// Drop any sessions seeded during the test.
		WP_Session_Tokens::get_instance( $this->user_id )->destroy_all();

		parent::tear_down();
	}

	/**
	 * Seeds one session for the given user and returns its token.
	 *
	 * @param int $user_id User to seed a session for.
	 * @return string The created session token.
	 */
	private function seed_session( int $user_id ): string {
		return WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-users/list-sessions' ) );
	}

	public function test_admin_reads_another_users_sessions(): void {
		$this->actingAs( 'administrator' );
		$this->seed_session( $this->user_id );

		$result = wp_get_ability( 'og-users/list-sessions' )->execute( array( 'user_id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->user_id, $result['user_id'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['sessions'] );

		$row = $result['sessions'][0];
		$this->assertArrayHasKey( 'expiration', $row );
		$this->assertIsInt( $row['expiration'] );
		$this->assertArrayHasKey( 'login', $row );
		$this->assertIsInt( $row['login'] );
		$this->assertIsString( $row['ip'] );
		$this->assertIsString( $row['user_agent'] );
	}

	public function test_default_reads_current_user(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->seed_session( $admin );

		// All-optional schema: pass an empty object so input validates (a null input
		// fails the type:object check); user_id then defaults to the current user.
		$result = wp_get_ability( 'og-users/list-sessions' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( $admin, $result['user_id'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['sessions'] );
	}

	public function test_non_editor_reading_another_user_gets_403_not_404(): void {
		$this->actingAs( 'subscriber' );
		$this->seed_session( $this->user_id );

		// The target user exists, so a subscriber who cannot edit them must get a
		// 403, not the 404 reserved for a missing user.
		$result = wp_get_ability( 'og-users/list-sessions' )->execute( array( 'user_id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_user_invalid_id', $result->get_error_code() );
	}

	public function test_missing_user_returns_invalid_id_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-sessions' )->execute( array( 'user_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-users/list-sessions' )->execute( array( 'user_id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_output_shape_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/list-sessions' )->execute( array( 'user_id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'user_id', 'sessions', 'total' ), array_keys( $result ) );
	}
}
