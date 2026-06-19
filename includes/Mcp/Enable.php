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
	 * the settings page.
	 */
	define( 'ABILITIES_CATALOG_MCP_ENABLED_OPTION', 'abilities_catalog_mcp_enabled' );
}

if ( ! defined( 'ABILITIES_CATALOG_MCP_EXPOSED_OPTION' ) ) {
	/**
	 * Option name that stores the per-ability exposure set.
	 *
	 * The value is a list of the ability names enabled for MCP execution. The set
	 * is deny-by-default: an ability absent from the list is exposed for `list` and
	 * `describe` but refused on `execute` until an administrator enables it. The
	 * settings page writes this option; {@see \GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy}
	 * reads it.
	 */
	define( 'ABILITIES_CATALOG_MCP_EXPOSED_OPTION', 'abilities_catalog_mcp_exposed_abilities' );
}

if ( ! defined( 'ABILITIES_CATALOG_MCP_SETTINGS_SLUG' ) ) {
	/**
	 * Admin page slug for the MCP server settings screen.
	 *
	 * Shared by the settings page that registers it and the exposure gate, whose
	 * "ability is disabled" error points the caller at this page.
	 */
	define( 'ABILITIES_CATALOG_MCP_SETTINGS_SLUG', 'abilities-catalog-mcp' );
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

if ( ! function_exists( 'abilities_catalog_mcp_is_enable_locked' ) ) {
	/**
	 * Reports whether the server enable flag is locked by the constant.
	 *
	 * When `ABILITIES_CATALOG_MCP_ENABLED` is defined it overrides the option, so the
	 * settings page must show the master toggle as locked and refuse to write it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the constant defines the enable flag (the option is ignored).
	 */
	function abilities_catalog_mcp_is_enable_locked(): bool {
		return defined( 'ABILITIES_CATALOG_MCP_ENABLED' );
	}
}

if ( ! function_exists( 'abilities_catalog_mcp_settings_url' ) ) {
	/**
	 * Returns the admin URL of the MCP server settings page.
	 *
	 * The exposure gate puts this URL in its "ability is disabled" error so the
	 * caller can point a human at the page that enables the ability. Built from a
	 * shared slug so the page and the gate never disagree on the location.
	 *
	 * @since 0.2.0
	 *
	 * @return string The absolute admin URL of the settings page.
	 */
	function abilities_catalog_mcp_settings_url(): string {
		return admin_url( 'options-general.php?page=' . ABILITIES_CATALOG_MCP_SETTINGS_SLUG );
	}
}
