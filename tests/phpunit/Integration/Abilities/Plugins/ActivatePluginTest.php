<?php
/**
 * Integration tests for the plugins/activate-plugin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises happy-path activation, the already-active no-op, missing-plugin 404,
 * and the capability gate, asserting the flat {plugin, status, name} output shape.
 *
 * Uses the bundled "Hello Dolly" single-file plugin (hello.php), which the test
 * environment ships inactive.
 */
final class ActivatePluginTest extends TestCase {

	private const PLUGIN = 'hello';

	public function tear_down(): void {
		deactivate_plugins( self::PLUGIN . '.php' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/activate-plugin' ) );
	}

	public function test_activates_an_inactive_plugin(): void {
		$this->actingAs( 'administrator' );
		deactivate_plugins( self::PLUGIN . '.php' );

		$result = wp_get_ability( 'plugins/activate-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::PLUGIN, $result['plugin'] );
		$this->assertSame( 'active', $result['status'] );
		$this->assertNotEmpty( $result['name'] );
		$this->assertTrue( is_plugin_active( self::PLUGIN . '.php' ) );
	}

	public function test_already_active_plugin_is_a_no_op_success(): void {
		$this->actingAs( 'administrator' );
		activate_plugin( self::PLUGIN . '.php' );

		$result = wp_get_ability( 'plugins/activate-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'active', $result['status'] );
	}

	/**
	 * Re-activating an already-active plugin is a no-op returning the same state, so
	 * the ability is classified idempotent (the no-op above is what that declares).
	 */
	public function test_idempotent_annotation_is_true(): void {
		$annotations = wp_get_ability( 'plugins/activate-plugin' )->get_meta()['annotations'];

		$this->assertTrue( $annotations['idempotent'] );
	}

	public function test_missing_plugin_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/activate-plugin' )->execute( array( 'plugin' => 'does-not-exist/does-not-exist' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_plugin_not_found', $result->get_error_code() );
	}

	public function test_empty_plugin_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/activate-plugin' )->execute( array( 'plugin' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/activate-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
