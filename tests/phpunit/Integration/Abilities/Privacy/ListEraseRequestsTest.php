<?php
/**
 * Integration tests for the privacy/list-erase-requests ability.
 *
 * Covers the happy-path listing (returns items + total with the exact declared
 * row keys), the status input filter, and the capability gate (which requires
 * both erase_others_personal_data and delete_users).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises privacy/list-erase-requests output shape, filtering, and the guard.
 */
final class ListEraseRequestsTest extends TestCase {

	/**
	 * Creates an erase-type user_request record and returns its ID.
	 *
	 * @param string $email Email address for the request.
	 * @return int The created user_request post ID.
	 */
	private function createEraseRequest( string $email = 'subject@example.com' ): int {
		$request_id = wp_create_user_request( $email, 'remove_personal_data' );
		$this->assertIsInt( $request_id );

		return $request_id;
	}

	public function test_listing_returns_items_and_total_with_declared_row_keys(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createEraseRequest( 'list-subject@example.com' );

		$result = wp_get_ability( 'privacy/list-erase-requests' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		// Locate the seeded row.
		$row = null;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $request_id ) {
				$row = $item;
				break;
			}
		}
		$this->assertNotNull( $row, 'The seeded erase request should appear in items.' );

		// Row shape: exactly the declared keys, in order.
		$this->assertSame(
			array( 'id', 'request_id', 'email', 'status', 'created', 'created_gmt', 'action_name' ),
			array_keys( $row )
		);
		$this->assertSame( $request_id, $row['id'] );
		$this->assertSame( $request_id, $row['request_id'] );
		$this->assertSame( 'list-subject@example.com', $row['email'] );
		$this->assertSame( 'remove_personal_data', $row['action_name'] );
		$this->assertSame( 'request-pending', $row['status'] );
		$this->assertNotSame( '', $row['created_gmt'] );
	}

	public function test_status_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		// Seed a pending erase request.
		$this->createEraseRequest( 'pending-subject@example.com' );

		// No confirmed requests exist; the filter must return an empty set.
		$result = wp_get_ability( 'privacy/list-erase-requests' )
			->execute( array( 'status' => 'request-confirmed' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'privacy/list-erase-requests' )
			->check_permissions( array() );

		$this->assertNotTrue( $allowed );
	}

	public function test_single_capability_is_not_enough(): void {
		// A user with only erase_others_personal_data (not delete_users) must be
		// denied: the gate requires both capabilities.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'erase_others_personal_data' );
		wp_set_current_user( $user_id );

		$allowed = wp_get_ability( 'privacy/list-erase-requests' )
			->check_permissions( array() );

		$this->assertNotTrue( $allowed );
	}
}
