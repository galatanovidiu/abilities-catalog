<?php
/**
 * Unit tests for the MCP Adapter plugin requirement check.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Unit\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\AdapterPlugin;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * The adapter is a soft runtime dependency, so the status rule is pure and asserted
 * directly: no version means the plugin is missing, a version below the floor is
 * outdated, and the floor itself or anything newer (including a pre-release tag) is
 * ready. The floor is pinned so a bump is a deliberate, visible change.
 */
final class AdapterPluginTest extends TestCase {

	/**
	 * The minimum adapter version is the 0.7.0 release that defines WP_MCP_VERSION.
	 *
	 * @return void
	 */
	public function test_minimum_version_is_pinned(): void {
		$this->assertSame( '0.7.0', AdapterPlugin::MIN_VERSION );
	}

	/**
	 * No installed version means the plugin is missing.
	 *
	 * @return void
	 */
	public function test_null_version_is_missing(): void {
		$this->assertSame( AdapterPlugin::STATUS_MISSING, AdapterPlugin::statusFor( null ) );
	}

	/**
	 * A version below the floor is outdated.
	 *
	 * @return void
	 */
	public function test_older_version_is_outdated(): void {
		$this->assertSame( AdapterPlugin::STATUS_OUTDATED, AdapterPlugin::statusFor( '0.6.2' ) );
	}

	/**
	 * The floor itself and every newer version are ready.
	 *
	 * @dataProvider ready_versions
	 *
	 * @param string $version An installed adapter version.
	 * @return void
	 */
	public function test_floor_and_newer_versions_are_ready( string $version ): void {
		$this->assertSame( AdapterPlugin::STATUS_READY, AdapterPlugin::statusFor( $version ) );
	}

	/**
	 * Versions that satisfy the floor.
	 *
	 * @return array<string,array{string}>
	 */
	public function ready_versions(): array {
		return array(
			'the floor itself' => array( '0.7.0' ),
			'a patch release'  => array( '0.7.1' ),
			'a pre-release'    => array( '1.0.0-beta' ),
		);
	}
}
