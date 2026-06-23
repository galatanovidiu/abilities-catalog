<?php
/**
 * Integration tests for the cron/get-event ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Cron;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single-object read ability: one scheduled WP-Cron event by hook
 * (plus exact args and an optional specific timestamp), against the declared
 * closed event projection, the dedicated 404 for a missing event (which must
 * not collapse to a permission denial), and the `manage_options` capability
 * guard.
 */
final class GetEventTest extends TestCase {

	private const TEST_HOOK = 'abilities_catalog_test_evt';

	protected function tearDown(): void {
		wp_clear_scheduled_hook( self::TEST_HOOK );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'cron/get-event' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'cron/get-event', $ability->get_name() );
	}

	public function test_happy_path_reads_back_a_recurring_event(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_event( $timestamp, 'hourly', self::TEST_HOOK );

		$result = wp_get_ability( 'cron/get-event' )->execute( array( 'hook' => self::TEST_HOOK ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::TEST_HOOK, $result['hook'] );
		$this->assertSame( $timestamp, $result['timestamp'] );
		$this->assertSame( 'hourly', $result['schedule'] );
		$this->assertSame( HOUR_IN_SECONDS, $result['interval'] );
		$this->assertSame( gmdate( 'c', $timestamp ), $result['gmt_date'] );
		$this->assertSame( array(), $result['args'] );
	}

	public function test_one_off_event_projects_schedule_and_interval_as_null(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, self::TEST_HOOK );

		$result = wp_get_ability( 'cron/get-event' )->execute(
			array(
				'hook'      => self::TEST_HOOK,
				'timestamp' => $timestamp,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $timestamp, $result['timestamp'] );
		// A one-off single event stores schedule=false in core; the projection
		// normalizes that to null and leaves interval null (no recurrence).
		$this->assertNull( $result['schedule'] );
		$this->assertNull( $result['interval'] );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::TEST_HOOK );

		$result = wp_get_ability( 'cron/get-event' )->execute( array( 'hook' => self::TEST_HOOK ) );

		$this->assertSame(
			array( 'hook', 'timestamp', 'gmt_date', 'schedule', 'interval', 'args' ),
			array_keys( $result )
		);
		$this->assertIsString( $result['hook'] );
		$this->assertIsInt( $result['timestamp'] );
		$this->assertIsString( $result['gmt_date'] );
		$this->assertIsString( $result['schedule'] );
		$this->assertIsInt( $result['interval'] );
		$this->assertIsArray( $result['args'] );
	}

	public function test_missing_event_returns_specific_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'cron/get-event' )->execute( array( 'hook' => 'no_such_cron_hook' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cron_event_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The specific not-found error must not collapse into the generic
		// permission denial.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'cron/get-event' );

		$this->assertFalse( $ability->check_permissions( array( 'hook' => self::TEST_HOOK ) ) );

		$result = $ability->execute( array( 'hook' => self::TEST_HOOK ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'cron/get-event' );

		$this->assertFalse( $ability->check_permissions( array( 'hook' => self::TEST_HOOK ) ) );

		$result = $ability->execute( array( 'hook' => self::TEST_HOOK ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
