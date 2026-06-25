<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Integration tests for the `og-network/list-networks` ability.
 *
 * @group multisite
 */
final class ListNetworksTest extends TestCase {

	/**
	 * Skips the suite on a non-multisite install.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	/**
	 * The ability registers and resolves.
	 */
	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/list-networks' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/list-networks', $ability->get_name() );
	}

	/**
	 * Happy path: a super admin lists the networks.
	 */
	public function test_super_admin_lists_networks(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/list-networks' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'networks', 'total' ), array_keys( $result ) );

		$this->assertIsArray( $result['networks'] );
		$this->assertNotEmpty( $result['networks'] );

		$row = $result['networks'][0];

		$keys = array_keys( $row );
		sort( $keys );
		$expected = array( 'domain', 'id', 'path', 'site_name' );
		sort( $expected );
		$this->assertSame( $expected, $keys );

		$this->assertIsInt( $row['id'] );
		$this->assertGreaterThan( 0, $row['id'] );
		$this->assertIsString( $row['domain'] );
		$this->assertIsString( $row['path'] );
		$this->assertIsString( $row['site_name'] );

		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	/**
	 * A plain administrator (not a super admin) is denied.
	 */
	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-network/list-networks' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A subscriber is denied.
	 */
	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-network/list-networks' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A logged-out caller is denied.
	 */
	public function test_logged_out_caller_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-network/list-networks' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
