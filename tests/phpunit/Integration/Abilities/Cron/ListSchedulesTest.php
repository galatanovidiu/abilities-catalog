<?php
/**
 * Integration tests for the cron/list-schedules ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Cron;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the core-function read ability: registered WP-Cron recurrence
 * schedules with name, display label, and interval in seconds, with the
 * declared closed object shape and the `manage_options` capability guard
 * enforced on execute().
 */
final class ListSchedulesTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'cron/list-schedules' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'cron/list-schedules', $ability->get_name() );
	}

	public function test_result_uses_closed_top_level_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'cron/list-schedules' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'schedules', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['schedules'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['schedules'] ), $result['total'] );
	}

	public function test_each_schedule_row_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'cron/list-schedules' )->execute();

		$this->assertNotEmpty( $result['schedules'] );

		foreach ( $result['schedules'] as $row ) {
			$this->assertSame(
				array( 'name', 'display', 'interval' ),
				array_keys( $row )
			);
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['display'] );
			$this->assertIsInt( $row['interval'] );
		}
	}

	public function test_core_hourly_schedule_is_listed_with_resolved_interval(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'cron/list-schedules' )->execute();

		$by_name = array();
		foreach ( $result['schedules'] as $row ) {
			$by_name[ $row['name'] ] = $row;
		}

		$this->assertArrayHasKey( 'hourly', $by_name, 'The core "hourly" schedule must be listed.' );
		$this->assertSame( HOUR_IN_SECONDS, $by_name['hourly']['interval'] );
		$this->assertSame( 'Once Hourly', $by_name['hourly']['display'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'cron/list-schedules' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'cron/list-schedules' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
