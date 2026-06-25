<?php
/**
 * Integration tests for the og-tools/set-transient ability.
 *
 * Covers registration, the output-shape contract
 * (stored/key/expiration/network), a happy-path store with a get_transient
 * read-back, overwriting an existing transient, an array value round-trip, the
 * network/site-transient variant, the read-back success signal (re-setting the
 * same value still reports stored=true), the manage_options capability gate for
 * logged-out and subscriber callers, and proofs that a denied call stores
 * nothing.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-tools/set-transient registration, store semantics, and the gate.
 */
final class SetTransientTest extends TestCase {

	/**
	 * Removes any transients the tests may have stored.
	 */
	protected function tearDown(): void {
		delete_transient( 'abilities_catalog_test_t' );
		delete_transient( 'abilities_catalog_test_arr' );
		delete_transient( 'abilities_catalog_guard_t' );
		delete_transient( 'abilities_catalog_guard_sub_t' );
		delete_site_transient( 'abilities_catalog_test_site_t' );

		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-tools/set-transient' ) );
	}

	public function test_output_schema_requires_the_full_field_set(): void {
		$schema = wp_get_ability( 'og-tools/set-transient' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame(
			array( 'stored', 'key', 'expiration', 'network' ),
			$schema['required']
		);
	}

	public function test_stores_value_and_reads_back(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'        => 'abilities_catalog_test_t',
				'value'      => 'v1',
				'expiration' => HOUR_IN_SECONDS,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'stored', 'key', 'expiration', 'network' ),
			array_keys( $result )
		);
		$this->assertTrue( $result['stored'] );
		$this->assertIsBool( $result['stored'] );
		$this->assertSame( 'abilities_catalog_test_t', $result['key'] );
		$this->assertSame( HOUR_IN_SECONDS, $result['expiration'] );
		$this->assertFalse( $result['network'] );

		// Side-effect read-back: the value is in the store.
		$this->assertSame( 'v1', get_transient( 'abilities_catalog_test_t' ) );
	}

	public function test_overwrites_existing_transient(): void {
		$this->actingAs( 'administrator' );

		set_transient( 'abilities_catalog_test_t', 'v1', HOUR_IN_SECONDS );

		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'   => 'abilities_catalog_test_t',
				'value' => 'v2',
			)
		);

		$this->assertTrue( $result['stored'] );
		$this->assertSame( 'v2', get_transient( 'abilities_catalog_test_t' ) );
	}

	public function test_re_setting_the_same_value_still_reports_stored(): void {
		$this->actingAs( 'administrator' );

		set_transient( 'abilities_catalog_test_t', 'same', HOUR_IN_SECONDS );

		// The raw set_transient() bool would be false here (value unchanged); the
		// read-back makes stored correctly report true.
		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'   => 'abilities_catalog_test_t',
				'value' => 'same',
			)
		);

		$this->assertTrue( $result['stored'] );
		$this->assertSame( 'same', get_transient( 'abilities_catalog_test_t' ) );
	}

	public function test_array_value_round_trips(): void {
		$this->actingAs( 'administrator' );

		$value = array(
			'a' => 1,
			'b' => array( 'nested', true ),
		);

		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'   => 'abilities_catalog_test_arr',
				'value' => $value,
			)
		);

		$this->assertTrue( $result['stored'] );
		$this->assertSame( $value, get_transient( 'abilities_catalog_test_arr' ) );
	}

	public function test_stores_site_transient_when_network_true(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'     => 'abilities_catalog_test_site_t',
				'value'   => 'site-cached',
				'network' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['stored'] );
		$this->assertTrue( $result['network'] );
		$this->assertSame(
			'site-cached',
			get_site_transient( 'abilities_catalog_test_site_t' )
		);
	}

	public function test_logged_out_user_is_denied_and_nothing_stored(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'   => 'abilities_catalog_guard_t',
				'value' => 'should-not-store',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// Nothing was stored under the key.
		$this->assertFalse( get_transient( 'abilities_catalog_guard_t' ) );
	}

	public function test_subscriber_is_denied_and_nothing_stored(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-tools/set-transient' )->execute(
			array(
				'key'   => 'abilities_catalog_guard_sub_t',
				'value' => 'should-not-store',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$this->assertFalse( get_transient( 'abilities_catalog_guard_sub_t' ) );
	}
}
