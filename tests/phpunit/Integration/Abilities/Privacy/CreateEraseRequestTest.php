<?php
/**
 * Integration tests for the privacy/create-erase-request ability.
 *
 * Covers the happy-path create (returns request_id, status, and action_name and
 * records a remove_personal_data user_request), the output shape, the
 * invalid-email and duplicate-request error paths, and the capability gate
 * (which requires both erase_others_personal_data and delete_users).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy\CreateEraseRequest;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises privacy/create-erase-request create, errors, and the capability guard.
 */
final class CreateEraseRequestTest extends TestCase {

	public function test_creating_a_request_returns_shape_and_pending_status(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'privacy/create-erase-request' )->execute(
			array( 'email' => 'subject@example.com' )
		);

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['request_id'] );
		$this->assertGreaterThan( 0, $result['request_id'] );
		$this->assertSame( 'request-pending', $result['status'] );
		$this->assertSame( 'remove_personal_data', $result['action_name'] );
		// Output shape: exactly the three declared keys.
		$this->assertSame( array( 'request_id', 'status', 'action_name' ), array_keys( $result ) );
		// A user_request record exists with the expected action.
		$this->assertSame( 'user_request', get_post_type( $result['request_id'] ) );
		$this->assertSame( 'remove_personal_data', get_post( $result['request_id'] )->post_name );
	}

	public function test_empty_email_returns_invalid_email_400(): void {
		$this->actingAs( 'administrator' );

		$result = ( new CreateEraseRequest() )->execute( array( 'email' => '   ' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_duplicate_request_is_rejected_with_core_code(): void {
		$this->actingAs( 'administrator' );
		// Pre-existing pending request for the same email + action.
		$existing = wp_create_user_request( 'dupe@example.com', 'remove_personal_data' );
		$this->assertIsInt( $existing );

		$result = ( new CreateEraseRequest() )->execute( array( 'email' => 'dupe@example.com' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'duplicate_request', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'privacy/create-erase-request' )
			->check_permissions( array( 'email' => 'subject@example.com' ) );

		$this->assertNotTrue( $allowed );
	}

	public function test_single_capability_is_not_enough(): void {
		// A user with only erase_others_personal_data (not delete_users) must be
		// denied: the gate requires both capabilities.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'erase_others_personal_data' );
		wp_set_current_user( $user_id );

		$allowed = wp_get_ability( 'privacy/create-erase-request' )
			->check_permissions( array( 'email' => 'subject@example.com' ) );

		$this->assertNotTrue( $allowed );
	}
}
