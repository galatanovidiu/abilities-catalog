<?php
/**
 * Integration tests for the og-network/get-network-option ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the network-option read ability: existence detection via a random
 * sentinel (so a stored false reports exists:true), the closed output shape, the
 * benign no-op for a never-set option, and the manage_network_options guard.
 *
 * @group multisite
 */
final class GetNetworkOptionTest extends TestCase {

	private const OPT_STRING = 'ac_test_opt';
	private const OPT_FALSE  = 'ac_test_false';
	private const OPT_MISSING = 'ac_test_never_set_option';

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	protected function tearDown(): void {
		delete_network_option( null, self::OPT_STRING );
		delete_network_option( null, self::OPT_FALSE );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/get-network-option' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/get-network-option', $ability->get_name() );
	}

	public function test_happy_path_reads_back_a_stored_string(): void {
		$this->actingAsSuperAdmin();
		update_network_option( null, self::OPT_STRING, 'hello' );

		$result = wp_get_ability( 'og-network/get-network-option' )->execute(
			array( 'option' => self::OPT_STRING )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['exists'] );
		$this->assertSame( 'hello', $result['value'] );
		$this->assertSame( self::OPT_STRING, $result['option'] );
		$this->assertIsInt( $result['network_id'] );
		$this->assertGreaterThanOrEqual( 1, $result['network_id'] );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();
		update_network_option( null, self::OPT_STRING, 'hello' );

		$result = wp_get_ability( 'og-network/get-network-option' )->execute(
			array( 'option' => self::OPT_STRING )
		);

		$this->assertSame(
			array( 'network_id', 'option', 'value', 'exists' ),
			array_keys( $result )
		);
	}

	public function test_stored_false_reports_exists_true(): void {
		$this->actingAsSuperAdmin();
		// add_network_option (not update_) actually persists a stored `false`:
		// update_network_option short-circuits when the new value equals the
		// default-`false` old value of a not-yet-existing option (option.php:2427).
		add_network_option( null, self::OPT_FALSE, false );

		$result = wp_get_ability( 'og-network/get-network-option' )->execute(
			array( 'option' => self::OPT_FALSE )
		);

		// The random sentinel (not a bare false) detects existence, so a
		// set-but-falsy option reports exists:true — distinct from the missing
		// case (exists:false, value:null). WordPress stores a scalar false as an
		// empty string in wp_sitemeta (maybe_serialize(false) === false → DB ''),
		// so the value round-trips as '' rather than literal false.
		$this->assertTrue( $result['exists'] );
		$this->assertSame( '', $result['value'] );
	}

	public function test_missing_option_is_a_benign_no_op(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/get-network-option' )->execute(
			array( 'option' => self::OPT_MISSING )
		);

		// A never-set option is a no-op read shape, not a 404.
		$this->assertIsArray( $result );
		$this->assertFalse( $result['exists'] );
		$this->assertNull( $result['value'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-network/get-network-option' );

		$this->assertFalse( $ability->check_permissions( array( 'option' => self::OPT_STRING ) ) );

		$result = $ability->execute( array( 'option' => self::OPT_STRING ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'og-network/get-network-option' );

		$this->assertFalse( $ability->check_permissions( array( 'option' => self::OPT_STRING ) ) );

		$result = $ability->execute( array( 'option' => self::OPT_STRING ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-network/get-network-option' );

		$this->assertFalse( $ability->check_permissions( array( 'option' => self::OPT_STRING ) ) );

		$result = $ability->execute( array( 'option' => self::OPT_STRING ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
