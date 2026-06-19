<?php
/**
 * Boot gate for the optional, off-by-default MCP server.
 *
 * Loaded unconditionally by the plugin bootstrap so any consumer — including the
 * later settings page — can ask whether the server is on without pulling in the
 * adapter vendor bundle. The ability catalog itself never depends on this file:
 * it registers its abilities whether the server is on or off.
 *
 * This is a procedural helper in the global namespace (not a class), so the
 * plugin's PSR-4 autoloader never touches it; the bootstrap requires it directly.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ABILITIES_CATALOG_MCP_ENABLED_OPTION' ) ) {
	/**
	 * Option name that stores the MCP server enable flag.
	 *
	 * Single source of truth shared by the gate below, the server bootstrap, and
	 * the later settings page.
	 */
	define( 'ABILITIES_CATALOG_MCP_ENABLED_OPTION', 'abilities_catalog_mcp_enabled' );
}

if ( ! function_exists( 'abilities_catalog_mcp_is_enabled' ) ) {
	/**
	 * Reports whether the optional MCP server should boot.
	 *
	 * Resolution order:
	 * 1. The `ABILITIES_CATALOG_MCP_ENABLED` constant (a hard override from
	 *    `wp-config.php`), when defined — its boolean wins over the option.
	 * 2. Otherwise the `abilities_catalog_mcp_enabled` option, default `false`.
	 *
	 * The server ships off; a site turns it on with the constant (the zero-UI path)
	 * or, later, with the settings page that flips the option.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the server should boot, false otherwise.
	 */
	function abilities_catalog_mcp_is_enabled(): bool {
		if ( defined( 'ABILITIES_CATALOG_MCP_ENABLED' ) ) {
			return (bool) constant( 'ABILITIES_CATALOG_MCP_ENABLED' );
		}

		return (bool) get_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION, false );
	}
}
