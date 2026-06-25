<?php
/**
 * Integration tests for the og-tools/get-transient ability.
 *
 * Covers registration, the output-shape contract (found/key/network/value), a
 * happy-path read with a scalar value, an array value that round-trips, the
 * missing-key benign result (found=false, value=null), the network/site-transient
 * variant, and the manage_options capability gate for logged-out and subscriber
 * callers.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-tools/get-transient registration, read semantics, and the gate.
 */
final class GetTransientTest extends TestCase {

	protected function tearDown(): void {
		delete_transient( 'abilities_catalog_test_t' );
		delete_site_transient( 'abilities_catalog_test_t' );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-tools/get-transient' ) );
	}

	public function test_output_schema_requires_found_key_network_value(): void {
		$schema = wp_get_ability( 'og-tools/get-transient' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'found', 'key', 'network', 'value' ), $schema['required'] );
		$this->assertSame(
			array( 'string', 'integer', 'number', 'boolean', 'object', 'array', 'null' ),
			$schema['properties']['value']['type']
		);
	}

	public function test_reads_existing_transient_value(): void {
		$this->actingAs( 'administrator' );

		set_transient( 'abilities_catalog_test_t', 'hello', HOUR_IN_SECONDS );

		$result = wp_get_ability( 'og-tools/get-transient' )->execute(
			array( 'key' => 'abilities_catalog_test_t' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'found', 'key', 'network', 'value' ), array_keys( $result ) );
		$this->assertTrue( $result['found'] );
		$this->assertSame( 'hello', $result['value'] );
		$this->assertSame( 'abilities_catalog_test_t', $result['key'] );
		$this->assertFalse( $result['network'] );
	}

	public function test_array_value_round_trips(): void {
		$this->actingAs( 'administrator' );

		$stored = array( 'a' => 1 );
		set_transient( 'abilities_catalog_test_t', $stored, HOUR_IN_SECONDS );

		$result = wp_get_ability( 'og-tools/get-transient' )->execute(
			array( 'key' => 'abilities_catalog_test_t' )
		);

		$this->assertTrue( $result['found'] );
		$this->assertSame( $stored, $result['value'] );
	}

	public function test_missing_transient_returns_found_false_and_null_value(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-tools/get-transient' )->execute(
			array( 'key' => 'abilities_catalog_transient_absent' )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['found'] );
		$this->assertNull( $result['value'] );
		$this->assertSame( 'abilities_catalog_transient_absent', $result['key'] );
	}

	public function test_reads_site_transient_when_network_true(): void {
		$this->actingAs( 'administrator' );

		set_site_transient( 'abilities_catalog_test_t', 'site-cached', HOUR_IN_SECONDS );

		$result = wp_get_ability( 'og-tools/get-transient' )->execute(
			array(
				'key'     => 'abilities_catalog_test_t',
				'network' => true,
			)
		);

		$this->assertTrue( $result['found'] );
		$this->assertSame( 'site-cached', $result['value'] );
		$this->assertTrue( $result['network'] );
	}

	public function test_logged_out_user_is_denied(): void {
		set_transient( 'abilities_catalog_test_t', 'secret', HOUR_IN_SECONDS );

		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-tools/get-transient' )->execute(
			array( 'key' => 'abilities_catalog_test_t' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		set_transient( 'abilities_catalog_test_t', 'secret', HOUR_IN_SECONDS );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-tools/get-transient' )->execute(
			array( 'key' => 'abilities_catalog_test_t' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
