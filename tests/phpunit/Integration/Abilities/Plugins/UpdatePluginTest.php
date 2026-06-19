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
 * Exercises the schema guard, missing-plugin 404, the up-to-date no-op, output
 * contract, and capability gate.
 *
 * A real wordpress.org update needs network access and a writable filesystem, so the
 * happy path (and the active-plugin reactivation that only runs after a successful
 * upgrade) is not run here; that matches the other dangerous install/update/delete
 * abilities, which assert their capability gate via DangerousTierPermissionTest and
 * their schema/contract here. The up-to-date no-op IS deterministic and is covered
 * below by priming the update_plugins transient with no offer.
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
		$this->assertSame( 'abilities_catalog_plugin_not_found', $result->get_error_code() );
	}

	public function test_output_schema_exposes_versions_and_reactivated(): void {
		$schema = wp_get_ability( 'plugins/update-plugin' )->get_output_schema();

		$this->assertSame(
			array( 'plugin', 'version', 'previous_version', 'updated', 'reactivated' ),
			$schema['required']
		);
		$this->assertArrayHasKey( 'previous_version', $schema['properties'] );
		$this->assertArrayHasKey( 'reactivated', $schema['properties'] );
	}

	/**
	 * An installed plugin with no offered update returns a stable no-op instead of
	 * collapsing the upgrader's falsy `up_to_date` return into a bogus 500. The
	 * returned `plugin` field is the suffix-free input form, so it round-trips into
	 * activate-plugin/get-plugin.
	 */
	public function test_up_to_date_plugin_returns_noop(): void {
		$this->actingAs( 'administrator' );

		// Prime a fresh, empty update offer so wp_update_plugins() does not rebuild it
		// (recent last_checked) and the preflight sees no update for "hello".
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'response'     => array(),
				'no_update'    => array(),
			)
		);

		$result = wp_get_ability( 'plugins/update-plugin' )->execute( array( 'plugin' => 'hello' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'hello', $result['plugin'] );
		$this->assertFalse( $result['updated'] );
		$this->assertFalse( $result['reactivated'] );
		$this->assertSame( $result['previous_version'], $result['version'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/update-plugin' )->execute( array( 'plugin' => 'hello' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
