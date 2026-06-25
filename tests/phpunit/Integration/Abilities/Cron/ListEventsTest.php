<?php
/**
 * Integration tests for the og-cron/list-events ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Cron;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the read ability that flattens the WP-Cron array into closed event
 * rows (hook, timestamp, gmt_date, schedule, interval, args) and enforces the
 * `manage_options` capability guard on execute().
 */
final class ListEventsTest extends TestCase {

	private const TEST_HOOK = 'abilities_catalog_test_evt';

	protected function tearDown(): void {
		wp_clear_scheduled_hook( self::TEST_HOOK );
		parent::tearDown();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-cron/list-events' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-cron/list-events', $ability->get_name() );
	}

	public function test_result_uses_closed_top_level_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-cron/list-events' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'events', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['events'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['events'] ), $result['total'] );
	}

	public function test_scheduled_single_event_appears_as_a_row(): void {
		$this->actingAs( 'administrator' );

		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, self::TEST_HOOK );

		$result = wp_get_ability( 'og-cron/list-events' )->execute();

		$row = $this->findRow( $result['events'], self::TEST_HOOK );

		$this->assertNotNull( $row, 'The scheduled test event must appear as a row.' );
		$this->assertSame( $timestamp, $row['timestamp'] );
		// A one-off single event normalizes core's `schedule => false` to null and
		// carries no interval.
		$this->assertNull( $row['schedule'] );
		$this->assertNull( $row['interval'] );
	}

	public function test_event_row_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::TEST_HOOK );

		$result = wp_get_ability( 'og-cron/list-events' )->execute();
		$row    = $this->findRow( $result['events'], self::TEST_HOOK );

		$this->assertNotNull( $row );
		$this->assertSame(
			array( 'hook', 'timestamp', 'gmt_date', 'schedule', 'interval', 'args' ),
			array_keys( $row )
		);
		$this->assertIsString( $row['hook'] );
		$this->assertIsInt( $row['timestamp'] );
		$this->assertIsString( $row['gmt_date'] );
		$this->assertNull( $row['schedule'] );
		$this->assertNull( $row['interval'] );
		$this->assertIsArray( $row['args'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-cron/list-events' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-cron/list-events' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Finds the first event row matching a hook name.
	 *
	 * @param array<int,array<string,mixed>> $events The projected event rows.
	 * @param string                         $hook   The hook name to find.
	 * @return array<string,mixed>|null The matching row, or null if absent.
	 */
	private function findRow( array $events, string $hook ): ?array {
		foreach ( $events as $row ) {
			if ( $row['hook'] === $hook ) {
				return $row;
			}
		}

		return null;
	}
}
