<?php
/**
 * Integration tests for the og-updates/run-update ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Updates;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Updates\RunUpdate;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Covers registration, the per-type capability mapping, and the unsupported-type
 * rejection (defense-in-depth) for the og-updates/run-update ability.
 *
 * The capability mapping and the unsupported-type guard are asserted directly on a
 * RunUpdate instance. The ability wrapper enforces the input schema (which excludes
 * "core") and the permission gate before execute() runs, so calling the class
 * methods directly is the only way to prove the in-method guards that exist as a
 * second line of defense. Calling execute(['type'=>'core']) returns its WP_Error
 * before any upgrader runs, so the test has no filesystem or network side effects.
 * The full happy path needs a real filesystem/network and is out of scope; the
 * capability gate is also proven for the whole tier in DangerousTierPermissionTest.
 */
final class RunUpdateTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-updates/run-update' ) );
	}

	public function test_permission_maps_plugin_type_to_update_plugins_cap(): void {
		$this->actingAs( 'administrator' );

		$ability = new RunUpdate();

		$this->assertTrue( $ability->hasPermission( array( 'type' => 'plugin' ) ) );
		$this->assertTrue( $ability->hasPermission( array( 'type' => 'theme' ) ) );
		$this->assertTrue( $ability->hasPermission( array( 'type' => 'translation' ) ) );
	}

	public function test_permission_denies_core_and_unknown_and_missing_types(): void {
		$this->actingAs( 'administrator' );

		$ability = new RunUpdate();

		$this->assertFalse( $ability->hasPermission( array( 'type' => 'core' ) ) );
		$this->assertFalse( $ability->hasPermission( array( 'type' => 'nonsense' ) ) );
		$this->assertFalse( $ability->hasPermission( array() ) );
	}

	public function test_subscriber_is_denied_each_supported_type(): void {
		$this->actingAs( 'subscriber' );

		$ability = new RunUpdate();

		$this->assertFalse( $ability->hasPermission( array( 'type' => 'plugin' ) ) );
		$this->assertFalse( $ability->hasPermission( array( 'type' => 'theme' ) ) );
		$this->assertFalse( $ability->hasPermission( array( 'type' => 'translation' ) ) );
	}

	public function test_execute_rejects_core_type_with_unsupported_error(): void {
		$this->actingAs( 'administrator' );

		$result = ( new RunUpdate() )->execute( array( 'type' => 'core' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_unsupported_update_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_execute_rejects_unknown_type_with_unsupported_error(): void {
		$this->actingAs( 'administrator' );

		$result = ( new RunUpdate() )->execute( array( 'type' => 'nonsense' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_unsupported_update_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}
}
