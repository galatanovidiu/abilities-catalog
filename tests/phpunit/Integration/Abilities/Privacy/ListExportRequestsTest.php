<?php
/**
 * Integration tests for the privacy/list-export-requests ability.
 *
 * Covers the happy-path listing (returns items + total with the exact declared
 * row keys and the export_personal_data action discriminator), the status input
 * filter, and the export-specific capability gate, which requires only
 * export_others_personal_data (unlike erase, which also needs delete_users).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises privacy/list-export-requests output shape, filtering, and the guard.
 */
final class ListExportRequestsTest extends TestCase {

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

	public function test_listing_returns_items_and_total_with_declared_row_keys(): void {
		$this->actingAs( 'administrator' );
		$request_id = $this->createExportRequest( 'list-subject@example.com' );

		$result = wp_get_ability( 'privacy/list-export-requests' )->execute( array() );

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
		$this->assertNotNull( $row, 'The seeded export request should appear in items.' );

		// Row shape: exactly the declared keys, in order.
		$this->assertSame(
			array( 'id', 'request_id', 'email', 'status', 'created', 'created_gmt', 'action_name' ),
			array_keys( $row )
		);
		$this->assertSame( $request_id, $row['id'] );
		$this->assertSame( $request_id, $row['request_id'] );
		$this->assertSame( 'list-subject@example.com', $row['email'] );
		$this->assertSame( 'export_personal_data', $row['action_name'] );
		$this->assertSame( 'request-pending', $row['status'] );
		$this->assertNotSame( '', $row['created_gmt'] );
	}

	public function test_status_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		// Seed a pending export request.
		$this->createExportRequest( 'pending-subject@example.com' );

		// No confirmed requests exist; the filter must return an empty set.
		$result = wp_get_ability( 'privacy/list-export-requests' )
			->execute( array( 'status' => 'request-confirmed' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'privacy/list-export-requests' )
			->check_permissions( array() );

		$this->assertNotTrue( $allowed );
	}

	public function test_export_capability_alone_is_sufficient(): void {
		// The export screen gates on export_others_personal_data, a meta capability
		// that map_meta_cap() rewrites to manage_options (wp-includes/capabilities.php:795).
		// Unlike the erase list, it must NOT also require delete_users. A user with
		// manage_options but no delete_users must pass the permission check; this
		// proves no erase-guard carry-over.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_options' );
		wp_set_current_user( $user_id );

		$this->assertFalse(
			current_user_can( 'delete_users' ),
			'Guard precondition: the test user must lack delete_users.'
		);

		$allowed = wp_get_ability( 'privacy/list-export-requests' )
			->check_permissions( array() );

		$this->assertTrue( $allowed );
	}
}
