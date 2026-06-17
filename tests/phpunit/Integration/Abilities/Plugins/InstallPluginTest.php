<?php
/**
 * Integration tests for the plugins/install-plugin ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the schema guard, output contract, and capability gate.
 *
 * A real wordpress.org install needs network access and a writable filesystem, so
 * it is not run here; that matches the other dangerous install/update/delete
 * abilities, which assert their capability gate via DangerousTierPermissionTest and
 * their schema/contract here. The focus is the schema tightening: an empty or
 * malformed slug must fail at input validation, not collapse into a generic
 * permission error.
 */
final class InstallPluginTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/install-plugin' ) );
	}

	public function test_empty_slug_is_rejected_by_schema_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/install-plugin' )->execute( array( 'slug' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_malformed_slug_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/install-plugin' )->execute( array( 'slug' => 'Akismet Plugin!' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_output_status_is_constrained_to_the_core_enum(): void {
		$schema = wp_get_ability( 'plugins/install-plugin' )->get_output_schema();

		$this->assertSame(
			array( 'inactive', 'active', 'network-active' ),
			$schema['properties']['status']['enum']
		);
		$this->assertSame(
			array( 'plugin', 'status', 'name' ),
			$schema['required']
		);
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/install-plugin' )->execute( array( 'slug' => 'akismet' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
