<?php
/**
 * Tests for the MCP server boot gate.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises `abilities_catalog_mcp_is_enabled()` over the option branch.
 *
 * The constant branch (`ABILITIES_CATALOG_MCP_ENABLED`) cannot be toggled within a
 * single PHP process — a constant, once defined, is immutable — so it is verified
 * live in wp-env rather than here. This suite pins the default-off behavior and the
 * option resolution that the (later) settings page will drive.
 */
final class EnableTest extends TestCase {

	/**
	 * Removes the enable option so each test starts from the shipped default (off).
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION );
		parent::tear_down();
	}

	/**
	 * With no constant and no option, the server is off.
	 *
	 * @return void
	 */
	public function test_disabled_by_default(): void {
		delete_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION );

		$this->assertFalse( abilities_catalog_mcp_is_enabled() );
	}

	/**
	 * A boolean-true option turns the server on.
	 *
	 * @return void
	 */
	public function test_enabled_when_option_is_true(): void {
		update_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION, true );

		$this->assertTrue( abilities_catalog_mcp_is_enabled() );
	}

	/**
	 * A truthy stored value (e.g. the string "1" a checkbox writes) turns it on.
	 *
	 * @return void
	 */
	public function test_enabled_when_option_is_truthy_string(): void {
		update_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION, '1' );

		$this->assertTrue( abilities_catalog_mcp_is_enabled() );
	}

	/**
	 * A falsey stored value keeps the server off.
	 *
	 * @return void
	 */
	public function test_disabled_when_option_is_falsey(): void {
		update_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION, '0' );

		$this->assertFalse( abilities_catalog_mcp_is_enabled() );
	}
}
