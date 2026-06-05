<?php
/**
 * Integration tests for the site-health/get-status ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\SiteHealth;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the Site Health status read: the capability guard, the shape of the
 * direct/async/summary structure, the status enum, and the summary counts.
 */
final class GetStatusTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'site-health/get-status' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'site-health/get-status', $ability->get_name() );
	}

	public function test_happy_path_returns_direct_async_summary(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'site-health/get-status' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'direct', $result );
		$this->assertArrayHasKey( 'async', $result );
		$this->assertArrayHasKey( 'summary', $result );

		$this->assertIsArray( $result['direct'] );
		$this->assertIsArray( $result['async'] );

		// The summary always carries the three status buckets.
		$this->assertSame(
			array( 'good', 'recommended', 'critical' ),
			array_keys( $result['summary'] )
		);

		// Direct tests actually ran: at least one core direct test is present.
		$this->assertNotEmpty( $result['direct'] );

		// Every direct status is within the core enum, and the summary counts
		// match the number of direct results per status.
		$counts = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);
		foreach ( $result['direct'] as $entry ) {
			$this->assertArrayHasKey( 'test', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertArrayHasKey( 'status', $entry );
			$this->assertContains(
				$entry['status'],
				array( 'good', 'recommended', 'critical' )
			);
			++$counts[ $entry['status'] ];
		}

		$this->assertSame( $counts, $result['summary'] );
		$this->assertSame( count( $result['direct'] ), array_sum( $result['summary'] ) );
	}

	public function test_async_tests_use_hyphenated_slugs(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'site-health/get-status' )->execute();

		foreach ( $result['async'] as $entry ) {
			$this->assertArrayHasKey( 'test', $entry );
			$this->assertArrayHasKey( 'label', $entry );
			// Runnable slugs are hyphenated, never underscored.
			$this->assertStringNotContainsString( '_', $entry['test'] );
		}
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'site-health/get-status' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
