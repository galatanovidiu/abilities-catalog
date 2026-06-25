<?php
/**
 * Integration tests for the og-privacy/create-export-request ability.
 *
 * Covers the happy-path create (returns request_id, status, and action_name and
 * records an export_personal_data user_request), the output shape, the
 * invalid-email and duplicate-request error paths, and the capability gate
 * (which requires export_others_personal_data).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy\CreateExportRequest;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-privacy/create-export-request create, errors, and the capability guard.
 */
final class CreateExportRequestTest extends TestCase {

	public function test_default_create_is_confirmed_and_returns_shape(): void {
		$this->actingAs( 'administrator' );

		// Default: send_confirmation_email omitted (false). Mirrors wp-admin's
		// unchecked box — the request is created already confirmed, not left
		// pending without a confirmation key (the B12 stranding bug).
		$result = wp_get_ability( 'og-privacy/create-export-request' )->execute(
			array( 'email' => 'subject@example.com' )
		);

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['request_id'] );
		$this->assertGreaterThan( 0, $result['request_id'] );
		$this->assertSame( 'request-confirmed', $result['status'] );
		$this->assertSame( 'export_personal_data', $result['action_name'] );
		// Output shape: exactly the three declared keys.
		$this->assertSame( array( 'request_id', 'status', 'action_name' ), array_keys( $result ) );
		// A user_request record exists with the expected action.
		$this->assertSame( 'user_request', get_post_type( $result['request_id'] ) );
		$this->assertSame( 'export_personal_data', get_post( $result['request_id'] )->post_name );
	}

	public function test_send_confirmation_email_creates_pending_with_confirmation_key(): void {
		$this->actingAs( 'administrator' );

		// send=true mirrors wp-admin's checked box: a pending request plus a
		// stored confirmation key, so the data subject can confirm via the link.
		// Short-circuit the actual mail transport (no SMTP in wp-env) so the
		// ability's success path is exercised; the key is generated before the
		// mail call, so it is stored regardless.
		add_filter( 'pre_wp_mail', '__return_true' );
		$result = wp_get_ability( 'og-privacy/create-export-request' )->execute(
			array(
				'email'                   => 'subject@example.com',
				'send_confirmation_email' => true,
			)
		);
		remove_filter( 'pre_wp_mail', '__return_true' );

		$this->assertIsArray( $result );
		$this->assertSame( 'request-pending', $result['status'] );
		$this->assertSame( 'export_personal_data', $result['action_name'] );
		// The confirmation key is stored (hashed into post_password); its absence
		// is exactly what stranded the old default path.
		$this->assertNotSame( '', (string) get_post( $result['request_id'] )->post_password );
	}

	public function test_empty_email_returns_invalid_email_400(): void {
		$this->actingAs( 'administrator' );

		$result = ( new CreateExportRequest() )->execute( array( 'email' => '   ' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_duplicate_request_is_rejected_with_core_code(): void {
		$this->actingAs( 'administrator' );
		// Pre-existing pending request for the same email + action.
		$existing = wp_create_user_request( 'dupe@example.com', 'export_personal_data' );
		$this->assertIsInt( $existing );

		$result = ( new CreateExportRequest() )->execute( array( 'email' => 'dupe@example.com' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'duplicate_request', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'og-privacy/create-export-request' )
			->check_permissions( array( 'email' => 'subject@example.com' ) );

		$this->assertNotTrue( $allowed );
	}
}
