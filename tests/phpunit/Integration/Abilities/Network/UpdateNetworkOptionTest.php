<?php
/**
 * Integration tests for the network/update-network-option ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network\UpdateNetworkOption;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the network-option write ability: the read-back-derived `updated`
 * flag (so re-writing an unchanged value reports true despite core's no-op
 * false), a mixed array value round-trip, the empty-option guard, the closed
 * output shape, and the dangerous-tier manage_network_options guard (the
 * execute()-top cap repeat denies a plain administrator).
 *
 * @group multisite
 */
final class UpdateNetworkOptionTest extends TestCase {

	private const OPT_STRING = 'ac_test_opt';
	private const OPT_ARRAY  = 'ac_test_array_opt';
	private const OPT_GUARD  = 'ac_test_guard_opt';

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	protected function tearDown(): void {
		delete_network_option( null, self::OPT_STRING );
		delete_network_option( null, self::OPT_ARRAY );
		delete_network_option( null, self::OPT_GUARD );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'network/update-network-option' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/update-network-option', $ability->get_name() );
	}

	public function test_happy_path_writes_and_reads_back_a_string(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/update-network-option' )->execute(
			array(
				'option' => self::OPT_STRING,
				'value'  => 'hello',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( self::OPT_STRING, $result['option'] );
		$this->assertIsInt( $result['network_id'] );
		$this->assertGreaterThanOrEqual( 1, $result['network_id'] );

		// Side-effect read-back via core.
		$this->assertSame( 'hello', get_network_option( null, self::OPT_STRING ) );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/update-network-option' )->execute(
			array(
				'option' => self::OPT_STRING,
				'value'  => 'hello',
			)
		);

		$this->assertSame(
			array( 'updated', 'network_id', 'option' ),
			array_keys( $result )
		);
	}

	public function test_unchanged_rewrite_still_reports_updated_true(): void {
		$this->actingAsSuperAdmin();

		$ability = wp_get_ability( 'network/update-network-option' );
		$input   = array(
			'option' => self::OPT_STRING,
			'value'  => 'hello',
		);

		$ability->execute( $input );

		// Re-writing the same value: core's update_network_option returns a no-op
		// false, but the read-back derivation must still report updated:true.
		$result = $ability->execute( $input );

		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'hello', get_network_option( null, self::OPT_STRING ) );
	}

	public function test_array_value_round_trips(): void {
		$this->actingAsSuperAdmin();

		$value = array( 'a' => 1 );

		$result = wp_get_ability( 'network/update-network-option' )->execute(
			array(
				'option' => self::OPT_ARRAY,
				'value'  => $value,
			)
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( $value, get_network_option( null, self::OPT_ARRAY ) );
	}

	public function test_empty_option_is_rejected_with_400(): void {
		$this->actingAsSuperAdmin();

		// The input schema declares option with minLength:1, so an empty string is
		// rejected by schema validation (ability_invalid_input) before the body's own
		// abilities_catalog_invalid_option guard is reached. Both are 400s.
		$result = wp_get_ability( 'network/update-network-option' )->execute(
			array(
				'option' => '',
				'value'  => 'x',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// Core's schema-validation error exposes only the code (no status array),
		// matching the repo convention for ability_invalid_input assertions.
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied_and_option_unchanged(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'network/update-network-option' );

		// The coarse permission gate (manage_network_options) refuses a plain
		// site administrator on multisite.
		$this->assertNotTrue(
			$ability->check_permissions(
				array(
					'option' => self::OPT_GUARD,
					'value'  => 'x',
				)
			)
		);

		// Calling the class method directly reaches the execute()-top cap repeat:
		// the WP_Ability::execute() wrapper runs check_permissions() first and would
		// short-circuit with 'ability_invalid_permissions' before the class body, so
		// instantiate the class to prove the defense-in-depth 403 (not a no-op write).
		$result = ( new UpdateNetworkOption() )->execute(
			array(
				'option' => self::OPT_GUARD,
				'value'  => 'x',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cannot_manage_network_options', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// The option was not written.
		$this->assertFalse( get_network_option( null, self::OPT_GUARD ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'network/update-network-option' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'option' => self::OPT_GUARD,
					'value'  => 'x',
				)
			)
		);

		$result = $ability->execute(
			array(
				'option' => self::OPT_GUARD,
				'value'  => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'network/update-network-option' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'option' => self::OPT_GUARD,
					'value'  => 'x',
				)
			)
		);

		$result = $ability->execute(
			array(
				'option' => self::OPT_GUARD,
				'value'  => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
