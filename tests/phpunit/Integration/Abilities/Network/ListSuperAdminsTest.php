<?php
/**
 * Integration tests for the network/list-super-admins ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the no-input read ability that resolves the network's super-admin
 * logins into closed rows (user_login, user_id, user_email, display_name) and
 * enforces the multisite + manage_network_users capability guard.
 *
 * @group multisite
 */
final class ListSuperAdminsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'network/list-super-admins' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/list-super-admins', $ability->get_name() );
	}

	public function test_lists_current_super_admin(): void {
		$user_id = $this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/list-super-admins' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'super_admins', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['super_admins'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['super_admins'] ), $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		$row = $this->findRowByUserId( $result['super_admins'], $user_id );

		$this->assertNotNull( $row, 'The acting super admin must appear as a row.' );
		$this->assertSame(
			array( 'user_login', 'user_id', 'user_email', 'display_name' ),
			array_keys( $row )
		);
		$this->assertSame( $user_id, $row['user_id'] );
		$this->assertIsString( $row['user_login'] );
		$this->assertNotSame( '', $row['user_email'], 'A resolved super admin has a non-empty email.' );
		$this->assertIsString( $row['display_name'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'network/list-super-admins' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'network/list-super-admins' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'network/list-super-admins' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Finds the first super-admin row matching a user ID.
	 *
	 * @param array<int,array<string,mixed>> $rows    The projected super-admin rows.
	 * @param int                            $user_id The user ID to find.
	 * @return array<string,mixed>|null The matching row, or null if absent.
	 */
	private function findRowByUserId( array $rows, int $user_id ): ?array {
		foreach ( $rows as $row ) {
			if ( $row['user_id'] === $user_id ) {
				return $row;
			}
		}

		return null;
	}
}
