<?php
/**
 * Integration tests for the og-cron/schedule-event ability.
 *
 * Covers registration, the happy-path single and recurring schedules with a
 * wp_next_scheduled read-back, the exact output-key-set contract, the duplicate
 * guard (409, original survives), a bad recurrence (400 carrying core's
 * invalid_schedule), and the manage_options gate for logged-out and subscriber
 * callers (denied, with no event scheduled).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Cron;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-cron/schedule-event registration, schedule semantics, and the gate.
 */
final class ScheduleEventTest extends TestCase {

	private const HOOK = 'abilities_catalog_test_evt';

	public function tear_down(): void {
		wp_clear_scheduled_hook( self::HOOK );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-cron/schedule-event' ) );
	}

	public function test_schedules_single_event_and_reads_back(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => $timestamp,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['scheduled'] );
		$this->assertSame( self::HOOK, $result['hook'] );
		$this->assertSame( $timestamp, $result['timestamp'] );
		$this->assertNull( $result['schedule'] );
		$this->assertNull( $result['interval'] );

		// Side-effect read-back via core.
		$this->assertSame( $timestamp, wp_next_scheduled( self::HOOK ) );
	}

	public function test_schedules_recurring_event(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'       => self::HOOK,
				'timestamp'  => $timestamp,
				'recurrence' => 'hourly',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['scheduled'] );
		$this->assertSame( 'hourly', $result['schedule'] );
		$this->assertSame( HOUR_IN_SECONDS, $result['interval'] );
		$this->assertSame( $timestamp, wp_next_scheduled( self::HOOK ) );
	}

	public function test_output_key_set_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => time() + HOUR_IN_SECONDS,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'scheduled', 'hook', 'timestamp', 'gmt_date', 'schedule', 'interval', 'args' ),
			array_keys( $result )
		);
		$this->assertIsString( $result['gmt_date'] );
		$this->assertIsArray( $result['args'] );
	}

	public function test_duplicate_event_is_rejected_with_409_and_original_survives(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;

		$first = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => $timestamp,
			)
		);
		$this->assertIsArray( $first );

		$second = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => $timestamp + 7200,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'abilities_catalog_cron_event_exists', $second->get_error_code() );
		$this->assertSame( 409, $second->get_error_data()['status'] );

		// The original event must survive unchanged.
		$this->assertSame( $timestamp, wp_next_scheduled( self::HOOK ) );
	}

	public function test_bad_recurrence_returns_400_carrying_core_invalid_schedule(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'       => self::HOOK,
				'timestamp'  => time() + HOUR_IN_SECONDS,
				'recurrence' => 'never',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_schedule', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertFalse( wp_next_scheduled( self::HOOK ) );
	}

	public function test_logged_out_user_is_denied_and_no_event_scheduled(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => time() + HOUR_IN_SECONDS,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertFalse( wp_next_scheduled( self::HOOK ) );
	}

	public function test_subscriber_is_denied_and_no_event_scheduled(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-cron/schedule-event' )->execute(
			array(
				'hook'      => self::HOOK,
				'timestamp' => time() + HOUR_IN_SECONDS,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertFalse( wp_next_scheduled( self::HOOK ) );
	}
}
