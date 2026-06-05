<?php
/**
 * Integration tests for the plugins/update-plugin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the schema guard, missing-plugin 404, output contract, and capability
 * gate.
 *
 * A real wordpress.org update needs network access and a writable filesystem, so the
 * happy path is not run here; that matches the other dangerous install/update/delete
 * abilities, which assert their capability gate via DangerousTierPermissionTest and
 * their schema/contract here. Up-to-date and active-plugin behavior are deferred items
 * and are not covered until those policies are resolved.
 */
final class UpdatePluginTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/update-plugin' ) );
	}

	public function test_empty_plugin_is_rejected_by_schema_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/update-plugin' )->execute( array( 'plugin' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_malformed_plugin_path_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/update-plugin' )->execute( array( 'plugin' => '../evil' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_not_installed_plugin_returns_not_found(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/update-plugin' )->execute( array( 'plugin' => 'does-not-exist/does-not-exist' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'webmcp_plugin_not_found', $result->get_error_code() );
	}

	public function test_output_schema_exposes_previous_version(): void {
		$schema = wp_get_ability( 'plugins/update-plugin' )->get_output_schema();

		$this->assertSame(
			array( 'plugin', 'version', 'previous_version', 'updated' ),
			$schema['required']
		);
		$this->assertArrayHasKey( 'previous_version', $schema['properties'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/update-plugin' )->execute( array( 'plugin' => 'hello' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
