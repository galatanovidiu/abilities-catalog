<?php
/**
 * Integration tests for the og-plugins/get-plugin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the adapter-backed read ability end-to-end: real WordPress data in,
 * the catalog's flat field set out, with the wrapped route's capability check
 * (`activate_plugins`) enforced at dispatch.
 *
 * Uses the bundled "Hello Dolly" single-file plugin (hello.php), which the test
 * environment ships installed.
 */
final class GetPluginTest extends TestCase {

	private const PLUGIN = 'hello';

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-plugins/get-plugin' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-plugins/get-plugin', $ability->get_name() );
	}

	public function test_admin_can_read_plugin_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-plugins/get-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertIsArray( $result );
		$this->assertSame( self::PLUGIN, $result['plugin'] );
		$this->assertNotEmpty( $result['name'] );
		$this->assertIsString( $result['version'] );
		$this->assertIsString( $result['description'] );
		$this->assertIsBool( $result['network_only'] );
		$this->assertContains( $result['status'], array( 'inactive', 'active', 'network-active' ) );
	}

	public function test_missing_plugin_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-plugins/get-plugin' )->execute( array( 'plugin' => 'does-not-exist/does-not-exist' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_plugin_not_found', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		// Adapter-backed: permission delegates to the wrapped route, whose
		// permission_callback requires activate_plugins. The denial now runs at
		// dispatch, so execute() surfaces the route's REAL error — not the generic
		// ability_invalid_permissions collapse — and this ability sets no
		// require_permission guard, so nothing is collapsed.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-plugins/get-plugin' )->execute( array( 'plugin' => self::PLUGIN ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_view_plugin', $result->get_error_code() );
		// 403 because the subscriber is logged in but lacks activate_plugins.
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
