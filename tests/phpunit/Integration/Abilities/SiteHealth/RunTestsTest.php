<?php
/**
 * Integration tests for the site-health/run-tests ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\SiteHealth;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single Site Health test runner: the capability guard, the flattened
 * output shape, and that a bad test slug yields a 400 rather than a permission deny.
 */
final class RunTestsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'site-health/run-tests' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'site-health/run-tests', $ability->get_name() );
	}

	public function test_happy_path_returns_flattened_result(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'site-health/run-tests' )->execute( array( 'test' => 'https-status' ) );

		$this->assertIsArray( $result );

		// Flattened, top-level fields, no wrapping `result` object.
		$this->assertArrayNotHasKey( 'result', $result );
		$this->assertArrayHasKey( 'test', $result );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'status', $result );

		// Core reports the test name in its own form (underscored).
		$this->assertSame( 'https_status', $result['test'] );
		$this->assertContains(
			$result['status'],
			array( 'good', 'recommended', 'critical' )
		);

		// Only the schema-declared keys are ever present.
		foreach ( array_keys( $result ) as $key ) {
			$this->assertContains(
				$key,
				array( 'test', 'label', 'status', 'badge', 'description' )
			);
		}
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'site-health/run-tests' );

		$this->assertFalse( $ability->check_permissions( array( 'test' => 'https-status' ) ) );

		$result = $ability->execute( array( 'test' => 'https-status' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_administrator_has_permission_regardless_of_slug(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'site-health/run-tests' );

		// A pure capability check: permission does not depend on the test slug, so an
		// unknown slug is never collapsed into a permission denial.
		$this->assertTrue( $ability->check_permissions( array( 'test' => 'no-such-test' ) ) );
	}

	public function test_unknown_test_slug_returns_input_error_not_permission_deny(): void {
		$this->actingAs( 'administrator' );

		// A slug outside the enum is rejected by input validation (400), never
		// collapsed into a permission denial. hasPermission() is now a pure
		// capability check, so a bad slug cannot masquerade as a permission failure.
		$result = wp_get_ability( 'site-health/run-tests' )->execute( array( 'test' => 'no-such-test' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}
}
