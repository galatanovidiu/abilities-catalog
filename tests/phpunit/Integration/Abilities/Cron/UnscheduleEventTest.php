<?php
/**
 * Integration tests for the og-cron/unschedule-event ability.
 *
 * Covers registration, the output-shape contract, a happy-path unschedule of a
 * recurring event with a wp_next_scheduled read-back and previous_schedule capture,
 * the existence pre-check (missing event returns a specific 404, NOT the misleading
 * could_not_set and NOT a permission collapse), and the manage_options gate for
 * logged-out and subscriber callers (proving the event survives a denied call).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Cron;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-cron/unschedule-event registration, removal semantics, and the gate.
 */
final class UnscheduleEventTest extends TestCase {

	private const TEST_HOOK = 'abilities_catalog_test_evt';

	protected function tearDown(): void {
		wp_clear_scheduled_hook( self::TEST_HOOK );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-cron/unschedule-event' ) );
	}

	public function test_unschedules_recurring_event_and_reads_back_gone(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::TEST_HOOK );
		$this->assertNotFalse( wp_next_scheduled( self::TEST_HOOK ) );

		$result = wp_get_ability( 'og-cron/unschedule-event' )->execute(
			array(
				'hook'      => self::TEST_HOOK,
				'timestamp' => $timestamp,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['unscheduled'] );
		$this->assertIsBool( $result['unscheduled'] );
		$this->assertSame( self::TEST_HOOK, $result['hook'] );
		$this->assertSame( $timestamp, $result['timestamp'] );
		$this->assertSame( 'hourly', $result['previous_schedule'] );
		$this->assertSame( HOUR_IN_SECONDS, $result['previous_interval'] );

		// Side-effect read-back: the event is gone.
		$this->assertFalse( wp_next_scheduled( self::TEST_HOOK ) );
	}

	public function test_output_has_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::TEST_HOOK );

		$result = wp_get_ability( 'og-cron/unschedule-event' )->execute(
			array(
				'hook'      => self::TEST_HOOK,
				'timestamp' => $timestamp,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'unscheduled', 'hook', 'timestamp', 'gmt_date', 'previous_schedule', 'previous_interval', 'args' ),
			array_keys( $result )
		);
	}

	public function test_missing_event_returns_specific_404_not_could_not_set_or_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-cron/unschedule-event' )->execute(
			array(
				'hook'      => 'no_such_cron_hook',
				'timestamp' => 2000000000,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cron_event_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The pre-check must surface the 404, NOT the misleading could_not_set save
		// error and NOT a permission collapse.
		$this->assertNotSame( 'could_not_set', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_event_survives(): void {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::TEST_HOOK );

		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-cron/unschedule-event' )->execute(
			array(
				'hook'      => self::TEST_HOOK,
				'timestamp' => $timestamp,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The event must survive the denied call.
		$this->assertNotFalse( wp_next_scheduled( self::TEST_HOOK ) );
	}

	public function test_subscriber_is_denied_and_event_survives(): void {
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::TEST_HOOK );

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-cron/unschedule-event' )->execute(
			array(
				'hook'      => self::TEST_HOOK,
				'timestamp' => $timestamp,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$this->assertNotFalse( wp_next_scheduled( self::TEST_HOOK ) );
	}
}
