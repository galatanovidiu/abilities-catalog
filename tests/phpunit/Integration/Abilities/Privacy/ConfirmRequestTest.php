<?php
/**
 * Integration tests for the privacy/confirm-request ability.
 *
 * Covers the happy-path confirm (returns request_id, status, and action_name and
 * moves the record to request-confirmed), the missing/non-confirmable error
 * paths, the unsupported-action-type guard in execute() (the direct-PHP path
 * where permission_callback is bypassed), the output shape, and the capability
 * gate for an unprivileged user.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy\ConfirmRequest;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises privacy/confirm-request confirm, errors, and the capability guard.
 */
final class ConfirmRequestTest extends TestCase {

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

	public function test_confirming_a_request_returns_shape_and_sets_confirmed(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createExportRequest();

		$result = ( new ConfirmRequest() )->execute( array( 'request_id' => $request_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $request_id, $result['request_id'] );
		$this->assertSame( 'request-confirmed', $result['status'] );
		$this->assertSame( 'export_personal_data', $result['action_name'] );
		// Output shape: exactly the three declared keys.
		$this->assertSame( array( 'request_id', 'status', 'action_name' ), array_keys( $result ) );
		// The record moved to request-confirmed.
		$this->assertSame( 'request-confirmed', get_post_status( $request_id ) );
	}

	public function test_missing_request_id_returns_404(): void {
		$this->actingAs( 'administrator' );

		$result = ( new ConfirmRequest() )->execute( array( 'request_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_already_confirmed_request_returns_409(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createExportRequest();
		wp_update_post(
			array(
				'ID'          => $request_id,
				'post_status' => 'request-confirmed',
			)
		);

		$result = ( new ConfirmRequest() )->execute( array( 'request_id' => $request_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_confirmable', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_unsupported_action_type_returns_400(): void {
		$this->actingAs( 'administrator' );
		// A confirmable user_request with an action_name this ability does not support.
		$request_id = wp_insert_post(
			array(
				'post_type'   => 'user_request',
				'post_name'   => 'some_unsupported_action',
				'post_title'  => 'subject@example.com',
				'post_status' => 'request-pending',
				'post_author' => 0,
			),
			true
		);
		$this->assertIsInt( $request_id );

		$result = ( new ConfirmRequest() )->execute( array( 'request_id' => $request_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_request_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		// The record was not confirmed.
		$this->assertSame( 'request-pending', get_post_status( $request_id ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$request_id = $this->createExportRequest();

		$allowed = wp_get_ability( 'privacy/confirm-request' )->check_permissions( array( 'request_id' => $request_id ) );

		$this->assertNotTrue( $allowed );
	}

	/**
	 * On the routed path (coarse permission gate now floors at
	 * manage_privacy_options), a missing request surfaces the specific 404 instead
	 * of the generic ability_invalid_permissions.
	 */
	public function test_routed_missing_request_id_surfaces_404(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'privacy/confirm-request' )->execute( array( 'request_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * A caller who clears the manage_privacy_options floor but lacks the per-type
	 * cap (delete_users for an erase request) gets the relocated 403 — even when
	 * the request is confirmable — and the status is left untouched.
	 */
	public function test_erase_request_without_delete_users_is_denied_in_execute(): void {
		$request_id = wp_create_user_request( 'erase@example.com', 'remove_personal_data' );
		$this->assertIsInt( $request_id );

		$this->actingAs( 'subscriber' );
		wp_get_current_user()->add_cap( 'manage_options' );

		$this->assertTrue( current_user_can( 'manage_privacy_options' ) );
		$this->assertFalse( current_user_can( 'delete_users' ) );

		$result = wp_get_ability( 'privacy/confirm-request' )->execute( array( 'request_id' => $request_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_confirm', $result->get_error_code() );
		$this->assertSame( rest_authorization_required_code(), $result->get_error_data()['status'] );
		$this->assertSame( 'request-pending', get_post_status( $request_id ) );
	}
}
