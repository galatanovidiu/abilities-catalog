<?php
/**
 * Integration tests for the plugins/deactivate-plugin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises happy-path deactivation, the already-inactive no-op, missing-plugin 404,
 * and the capability gate, asserting the flat {plugin, status, previous_status} output
 * shape.
 *
 * Uses the bundled "Hello Dolly" single-file plugin (hello.php), which the test
 * environment ships inactive.
 */
final class DeactivatePluginTest extends TestCase {

	private const PLUGIN = 'hello';

	public function tear_down(): void {
		deactivate_plugins( self::PLUGIN . '.php' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/deactivate-plugin' ) );
	}

	public function test_deactivates_an_active_plugin(): void {
		$this->actingAs( 'administrator' );
		activate_plugin( self::PLUGIN . '.php' );

		$result = wp_get_ability( 'plugins/deactivate-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::PLUGIN, $result['plugin'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertSame( 'active', $result['previous_status'] );
		$this->assertFalse( is_plugin_active( self::PLUGIN . '.php' ) );
	}

	public function test_already_inactive_plugin_is_a_no_op_success(): void {
		$this->actingAs( 'administrator' );
		deactivate_plugins( self::PLUGIN . '.php' );

		$result = wp_get_ability( 'plugins/deactivate-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertSame( 'inactive', $result['previous_status'] );
	}

	public function test_missing_plugin_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/deactivate-plugin' )->execute( array( 'plugin' => 'does-not-exist/does-not-exist' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_plugin_not_found', $result->get_error_code() );
	}

	public function test_empty_plugin_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/deactivate-plugin' )->execute( array( 'plugin' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'ability_invalid_input', 'ability_invalid_permissions', 'webmcp_missing_plugin' )
		);
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/deactivate-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
