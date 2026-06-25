<?php
/**
 * Integration tests for the og-network/get-network ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the network-level single-object read ability: one multisite network
 * by id (or the current network when omitted), against the declared closed
 * projection, the dedicated 404 for an unknown network (which must not collapse
 * to a permission denial), and the multisite + manage_network capability guard.
 *
 * @group multisite
 */
final class GetNetworkTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/get-network' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/get-network', $ability->get_name() );
	}

	public function test_happy_path_returns_the_current_network(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/get-network' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['id'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertIsInt( $result['main_site_id'] );
		$this->assertGreaterThanOrEqual( 1, $result['main_site_id'] );
		$this->assertIsString( $result['domain'] );
		$this->assertIsString( $result['path'] );
		$this->assertIsString( $result['site_name'] );
		$this->assertIsString( $result['cookie_domain'] );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/get-network' )->execute( array() );

		$this->assertSame(
			array( 'id', 'domain', 'path', 'site_name', 'cookie_domain', 'main_site_id' ),
			array_keys( $result )
		);
	}

	public function test_unknown_network_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/get-network' )->execute( array( 'network_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_network_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The specific not-found error must not collapse into the generic
		// permission denial.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'og-network/get-network' );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-network/get-network' );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-network/get-network' );

		$this->assertFalse( $ability->check_permissions( array() ) );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
