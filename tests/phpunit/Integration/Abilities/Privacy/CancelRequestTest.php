<?php
/**
 * Integration tests for the privacy/cancel-request ability.
 *
 * Covers the happy-path delete (returns request_id + cancelled and removes the
 * user_request record), the permission gate denying missing/non-user_request
 * IDs, the output shape, and the capability gate for an unprivileged user.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises privacy/cancel-request delete, errors, and the capability guard.
 */
final class CancelRequestTest extends TestCase {

	/**
	 * Creates an export-type user_request record and returns its ID.
	 *
	 * @param string $email Email address for the request.
	 * @return int The created user_request post ID.
	 */
	private function createExportRequest( string $email = 'subject@example.com' ): int {
		$request_id = wp_create_user_request( $email, 'export_personal_data' );
		$this->assertIsInt( $request_id );

		return $request_id;
	}

	public function test_deleting_a_request_returns_request_id_and_cancelled(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createExportRequest();

		$result = wp_get_ability( 'privacy/cancel-request' )->execute( array( 'request_id' => $request_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $request_id, $result['request_id'] );
		$this->assertTrue( $result['cancelled'] );
		// Output shape: exactly the two declared keys.
		$this->assertSame( array( 'request_id', 'cancelled' ), array_keys( $result ) );
		// The record is gone.
		$this->assertNull( get_post( $request_id ) );
	}

	public function test_missing_request_id_is_denied_by_permission_gate(): void {
		$this->actingAs( 'administrator' );

		// The permission gate loads the request first and denies when it does
		// not resolve to a user_request, so execute()'s own 404 branch is never
		// reached through the wrapped path.
		$result = wp_get_ability( 'privacy/cancel-request' )->execute( array( 'request_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_non_user_request_post_is_denied_by_permission_gate(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$result = wp_get_ability( 'privacy/cancel-request' )->execute( array( 'request_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		// The unrelated post is untouched.
		$this->assertNotNull( get_post( $post_id ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$request_id = $this->createExportRequest();

		$allowed = wp_get_ability( 'privacy/cancel-request' )->check_permissions( array( 'request_id' => $request_id ) );

		$this->assertNotTrue( $allowed );
	}
}
