<?php
/**
 * Integration tests for the users/destroy-all-sessions ability.
 *
 * Covers registration, the output-shape contract (destroyed/user_id/sessions_ended),
 * a happy-path destroy of a seeded user's sessions with a get_all() read-back proving
 * they are gone, the missing-user 404 (not a permission collapse), and the dangerous-
 * tier edit_users gate for subscriber and logged-out callers — each proving the
 * target's sessions survive.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Session_Tokens;

/**
 * Exercises users/destroy-all-sessions registration, destroy semantics, and the gate.
 */
final class DestroyAllSessionsTest extends TestCase {

	/**
	 * User whose sessions are seeded and ended.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		WP_Session_Tokens::get_instance( $this->user_id )->destroy_all();
		parent::tear_down();
	}

	/**
	 * Seeds the given number of active sessions for the user under test.
	 *
	 * @param int $count How many sessions to create.
	 */
	private function seedSessions( int $count ): void {
		$manager = WP_Session_Tokens::get_instance( $this->user_id );
		for ( $i = 0; $i < $count; $i++ ) {
			$manager->create( time() + DAY_IN_SECONDS );
		}
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'users/destroy-all-sessions' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'users/destroy-all-sessions', $ability->get_name() );
	}

	public function test_happy_path_ends_all_sessions(): void {
		$this->actingAs( 'administrator' );
		$this->seedSessions( 3 );

		$result = wp_get_ability( 'users/destroy-all-sessions' )->execute(
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'destroyed', 'user_id', 'sessions_ended' ), array_keys( $result ) );
		$this->assertTrue( $result['destroyed'] );
		$this->assertSame( $this->user_id, $result['user_id'] );
		$this->assertGreaterThanOrEqual( 1, $result['sessions_ended'] );
		$this->assertSame( 3, $result['sessions_ended'] );

		// Side-effect read-back: no sessions remain for the user.
		$this->assertSame( array(), WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_output_field_types(): void {
		$this->actingAs( 'administrator' );
		$this->seedSessions( 1 );

		$result = wp_get_ability( 'users/destroy-all-sessions' )->execute(
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsBool( $result['destroyed'] );
		$this->assertIsInt( $result['user_id'] );
		$this->assertIsInt( $result['sessions_ended'] );
	}

	public function test_missing_user_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/destroy-all-sessions' )->execute(
			array( 'user_id' => 99999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied_and_sessions_survive(): void {
		$this->seedSessions( 2 );
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'users/destroy-all-sessions' )->execute(
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The subscriber lacks edit_users, so the seeded sessions must survive.
		$this->assertCount( 2, WP_Session_Tokens::get_instance( $this->user_id )->get_all() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'users/destroy-all-sessions' )->execute(
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
