<?php
/**
 * Integration tests for the network/revoke-super-admin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network\RevokeSuperAdmin;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the dangerous network write that revokes a user's super-admin
 * privileges via revoke_super_admin(), proving the multisite + execute()-top
 * manage_network_users guard, the honest no-op for a non-super-admin, and that
 * a non-super-admin caller cannot revoke anyone.
 *
 * @group multisite
 */
final class RevokeSuperAdminTest extends TestCase {

	/**
	 * User IDs granted super admin during a test, revoked in tear_down().
	 *
	 * @var int[]
	 */
	private $granted = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->granted as $user_id ) {
			if ( is_super_admin( $user_id ) ) {
				revoke_super_admin( $user_id );
			}
		}

		$this->granted = array();

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'network/revoke-super-admin' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/revoke-super-admin', $ability->get_name() );
	}

	public function test_revokes_super_admin_and_reports_end_state(): void {
		$this->actingAsSuperAdmin();

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $target );
		$this->granted[] = $target;
		$this->assertTrue( is_super_admin( $target ) );

		$result = wp_get_ability( 'network/revoke-super-admin' )->execute( array( 'user_id' => $target ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'revoked', 'user_id', 'is_super_admin' ), array_keys( $result ) );
		$this->assertTrue( $result['revoked'] );
		$this->assertSame( $target, $result['user_id'] );
		$this->assertFalse( $result['is_super_admin'] );

		// Read-back: the user is no longer a super admin.
		$this->assertFalse( is_super_admin( $target ) );
	}

	public function test_revoking_non_super_admin_is_benign_no_op(): void {
		$this->actingAsSuperAdmin();

		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( is_super_admin( $target ) );

		$result = wp_get_ability( 'network/revoke-super-admin' )->execute( array( 'user_id' => $target ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['revoked'] );
		$this->assertFalse( $result['is_super_admin'] );
	}

	public function test_unknown_user_returns_specific_404(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/revoke-super-admin' )->execute( array( 'user_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied_and_target_unchanged(): void {
		// Seed a granted target as super admin first.
		$this->actingAsSuperAdmin();
		$target = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $target );
		$this->granted[] = $target;
		$this->assertTrue( is_super_admin( $target ) );

		// A plain administrator (not a super admin) is denied.
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'network/revoke-super-admin' );

		$this->assertNotTrue( $ability->check_permissions( array( 'user_id' => $target ) ) );

		// The WP_Ability::execute() wrapper runs check_permissions() first and would
		// short-circuit with 'ability_invalid_permissions' before the class body, so
		// instantiate the class directly to reach and prove the execute()-top guard.
		$result = ( new RevokeSuperAdmin() )->execute( array( 'user_id' => $target ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cannot_manage_network_users', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// The target is STILL a super admin: the denied call did not revoke.
		$this->assertTrue( is_super_admin( $target ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'network/revoke-super-admin' );

		$this->assertNotTrue( $ability->check_permissions( array( 'user_id' => 1 ) ) );

		$result = $ability->execute( array( 'user_id' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'network/revoke-super-admin' );

		$this->assertNotTrue( $ability->check_permissions( array( 'user_id' => 1 ) ) );

		$result = $ability->execute( array( 'user_id' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
