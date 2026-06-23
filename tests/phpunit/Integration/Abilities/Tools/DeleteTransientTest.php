<?php
/**
 * Integration tests for the tools/delete-transient ability.
 *
 * Covers registration, the output-shape contract (deleted/key), a happy-path
 * delete with a get_transient read-back, the network/site-transient variant, the
 * no-op case (deleting an absent transient returns deleted=false, not an error),
 * the manage_options capability gate for logged-out and subscriber callers, and a
 * proof that a denied call leaves the transient in place.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Tools;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises tools/delete-transient registration, delete semantics, and the gate.
 */
final class DeleteTransientTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'tools/delete-transient' ) );
	}

	public function test_output_schema_requires_deleted_and_key(): void {
		$schema = wp_get_ability( 'tools/delete-transient' )->get_output_schema();

		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'deleted', 'key' ), $schema['required'] );
	}

	public function test_deletes_existing_transient_and_reads_back_gone(): void {
		$this->actingAs( 'administrator' );

		set_transient( 'ac_test_transient', 'cached-value', HOUR_IN_SECONDS );
		$this->assertSame( 'cached-value', get_transient( 'ac_test_transient' ) );

		$result = wp_get_ability( 'tools/delete-transient' )->execute(
			array( 'key' => 'ac_test_transient' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'deleted', 'key' ), array_keys( $result ) );
		$this->assertTrue( $result['deleted'] );
		$this->assertIsBool( $result['deleted'] );
		$this->assertSame( 'ac_test_transient', $result['key'] );

		// Side-effect read-back: the transient is gone.
		$this->assertFalse( get_transient( 'ac_test_transient' ) );
	}

	public function test_deletes_site_transient_when_network_true(): void {
		$this->actingAs( 'administrator' );

		set_site_transient( 'ac_test_site_transient', 'site-cached', HOUR_IN_SECONDS );
		$this->assertSame( 'site-cached', get_site_transient( 'ac_test_site_transient' ) );

		$result = wp_get_ability( 'tools/delete-transient' )->execute(
			array(
				'key'     => 'ac_test_site_transient',
				'network' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( get_site_transient( 'ac_test_site_transient' ) );
	}

	public function test_missing_transient_is_a_benign_no_op(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'tools/delete-transient' )->execute(
			array( 'key' => 'ac_transient_that_does_not_exist' )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertSame( 'ac_transient_that_does_not_exist', $result['key'] );
	}

	public function test_logged_out_user_is_denied_and_transient_survives(): void {
		set_transient( 'ac_guard_transient', 'still-here', HOUR_IN_SECONDS );

		wp_set_current_user( 0 );

		$result = wp_get_ability( 'tools/delete-transient' )->execute(
			array( 'key' => 'ac_guard_transient' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The transient must survive the denied call.
		$this->assertSame( 'still-here', get_transient( 'ac_guard_transient' ) );
	}

	public function test_subscriber_is_denied_and_transient_survives(): void {
		set_transient( 'ac_guard_transient_sub', 'still-here', HOUR_IN_SECONDS );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'tools/delete-transient' )->execute(
			array( 'key' => 'ac_guard_transient_sub' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$this->assertSame( 'still-here', get_transient( 'ac_guard_transient_sub' ) );
	}
}
