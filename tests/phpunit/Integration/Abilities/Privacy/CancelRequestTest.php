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

	/**
	 * Creates an erase-type user_request record and returns its ID.
	 *
	 * @param string $email Email address for the request.
	 * @return int The created user_request post ID.
	 */
	private function createEraseRequest( string $email = 'erase@example.com' ): int {
		$request_id = wp_create_user_request( $email, 'remove_personal_data' );
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

	public function test_missing_request_id_surfaces_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// The coarse permission gate now floors at manage_privacy_options only, so
		// execute()'s specific 404 reaches the caller instead of the generic
		// ability_invalid_permissions the object-level pre-check used to produce.
		$result = wp_get_ability( 'privacy/cancel-request' )->execute( array( 'request_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_non_user_request_post_surfaces_404_and_is_untouched(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$result = wp_get_ability( 'privacy/cancel-request' )->execute( array( 'request_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// The unrelated post is untouched.
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * A caller who clears the manage_privacy_options floor but lacks the per-type
	 * cap (delete_users for an erase request) gets the relocated 403, and the
	 * record survives. Proves the per-type guard is preserved in execute().
	 */
	public function test_erase_request_without_delete_users_is_denied_in_execute(): void {
		$request_id = $this->createEraseRequest();

		// manage_options grants manage_privacy_options + erase_others_personal_data
		// (core remaps both to manage_options), but NOT delete_users.
		$this->actingAs( 'subscriber' );
		wp_get_current_user()->add_cap( 'manage_options' );

		$this->assertTrue( current_user_can( 'manage_privacy_options' ) );
		$this->assertFalse( current_user_can( 'delete_users' ) );

		$result = wp_get_ability( 'privacy/cancel-request' )->execute( array( 'request_id' => $request_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		$this->assertSame( rest_authorization_required_code(), $result->get_error_data()['status'] );
		// The record survives the denied cancel.
		$this->assertNotNull( get_post( $request_id ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$request_id = $this->createExportRequest();

		$allowed = wp_get_ability( 'privacy/cancel-request' )->check_permissions( array( 'request_id' => $request_id ) );

		$this->assertNotTrue( $allowed );
	}
}
