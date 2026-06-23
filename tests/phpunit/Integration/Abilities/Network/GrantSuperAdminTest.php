<?php
/**
 * Integration tests for the network/grant-super-admin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network\GrantSuperAdmin;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the dangerous network write that grants a user network-wide
 * super-admin privileges, enforcing the multisite + manage_network_users
 * capability guard and the benign already-super-admin no-op.
 *
 * @group multisite
 */
final class GrantSuperAdminTest extends TestCase {

	/**
	 * Users granted super admin during a test, revoked in tear_down.
	 *
	 * @var array<int,int>
	 */
	private array $granted = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->granted as $user_id ) {
			revoke_super_admin( $user_id );
		}
		$this->granted = array();

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'network/grant-super-admin' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/grant-super-admin', $ability->get_name() );
	}

	public function test_grants_super_admin(): void {
		$this->actingAsSuperAdmin();

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->granted[] = $target;

		$this->assertFalse( is_super_admin( $target ) );

		$result = wp_get_ability( 'network/grant-super-admin' )->execute( array( 'user_id' => $target ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'granted', 'user_id', 'is_super_admin' ), array_keys( $result ) );
		$this->assertTrue( $result['granted'] );
		$this->assertSame( $target, $result['user_id'] );
		$this->assertTrue( $result['is_super_admin'] );

		// Read-back: the user is now a super admin.
		$this->assertTrue( is_super_admin( $target ) );
	}

	public function test_already_super_admin_is_benign_no_op(): void {
		$this->actingAsSuperAdmin();

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->granted[] = $target;

		$ability = wp_get_ability( 'network/grant-super-admin' );
		$ability->execute( array( 'user_id' => $target ) );

		// Second grant for the same user: granted is false, but the end state holds.
		$result = $ability->execute( array( 'user_id' => $target ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['granted'] );
		$this->assertTrue( $result['is_super_admin'] );
	}

	public function test_unknown_user_returns_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/grant-super-admin' )->execute( array( 'user_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ability = wp_get_ability( 'network/grant-super-admin' );

		$this->assertNotTrue( $ability->check_permissions( array( 'user_id' => $target ) ) );

		// The WP_Ability::execute() wrapper runs check_permissions() first and would
		// short-circuit with 'ability_invalid_permissions' before the class body, so
		// instantiate the class directly to reach and prove the execute()-top guard:
		// a non-super-admin is denied and the target is NOT granted.
		$result = ( new GrantSuperAdmin() )->execute( array( 'user_id' => $target ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cannot_manage_network_users', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( is_super_admin( $target ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ability = wp_get_ability( 'network/grant-super-admin' );

		$this->assertNotTrue( $ability->check_permissions( array( 'user_id' => $target ) ) );

		$result = $ability->execute( array( 'user_id' => $target ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( is_super_admin( $target ) );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ability = wp_get_ability( 'network/grant-super-admin' );

		$this->assertNotTrue( $ability->check_permissions( array( 'user_id' => $target ) ) );

		$result = $ability->execute( array( 'user_id' => $target ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( is_super_admin( $target ) );
	}
}
