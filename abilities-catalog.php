<?php
/**
 * Plugin Name:       Abilities Catalog
 * Plugin URI:        https://github.com/galatanovidiu/abilities-catalog
 * Description:       Registers WordPress wp-admin features as Abilities API abilities, plus an optional, off-by-default MCP server that runs on the MCP Adapter plugin when it is installed. Consumer-agnostic: the catalog works standalone, and any Abilities API consumer can expose the abilities.
 * Version:           0.4.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Ovidiu Galatan
 * Author URI:        https://github.com/galatanovidiu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       abilities-catalog
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABILITIES_CATALOG_VERSION', '0.4.1' );
define( 'ABILITIES_CATALOG_FILE', __FILE__ );
define( 'ABILITIES_CATALOG_DIR', plugin_dir_path( __FILE__ ) );

/**
 * No-build PSR-4 autoloader for the `GalatanOvidiu\AbilitiesCatalog\` namespace.
 *
 * Maps the namespace root to the `includes/` directory, mirroring the
 * adapter's no-build ethos (no Composer step). Registered before the bootstrap
 * so the Registry and ability classes load on demand.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ABILITIES_CATALOG_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- PSR-4 path built from a plugin constant and an internal class name, not user input.
		require_once $path;
	}
);

// Always-loaded boot gate for the optional MCP server. Defines the global
// `abilities_catalog_mcp_is_enabled()` helper; it carries no adapter dependency,
// so the catalog stays standalone whether the server is on or off.
require_once __DIR__ . '/includes/Mcp/Enable.php';

add_action(
	'plugins_loaded',
	static function (): void {
		// vendor/ carries only the REST adapter (galatanovidiu/abilities-rest-adapter)
		// the ability classes wrap internal REST routes with. The MCP Adapter is NOT in
		// here: it is the separately installed MCP Adapter plugin, a soft dependency
		// the MCP boot block below checks for at runtime.
		$abilities_catalog_autoload_packages = ABILITIES_CATALOG_DIR . 'vendor/autoload_packages.php';
		if ( is_readable( $abilities_catalog_autoload_packages ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path built from a plugin constant and an internal literal, not user input.
			require_once $abilities_catalog_autoload_packages;
		}

		( new Registry() )->register();

		// The Abilities API ships with WordPress 6.9; without it the MCP layer has
		// nothing to expose, so skip all of it (the catalog above already no-ops).
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// On a multisite network, let an agent target a site by passing an optional
		// blog_id to any site-scoped ability; the decorator switches into that blog
		// around the capability check and execute, balanced. Single-site is a no-op.
		// Always-on (independent of the optional MCP server) — blog_id targeting is a
		// global concern, so it registers alongside Registry::register(), before
		// wp_abilities_api_init fires.
		( new Support\PolicyDecorator() )->register();

		// Snapshot whether the MCP Adapter plugin is here, once, before anything reads
		// it: the servers below, the admin notice, and the settings page's REST payload
		// must all answer the same way (see AdapterPlugin::detect()).
		Mcp\AdapterPlugin::detect();

		// The MCP server's settings page and its exposure REST API are always
		// available — independent of whether the server is on — so a site can turn the
		// server on and gate its abilities from one screen in wp-admin.
		Mcp\Admin\SettingsPage::register();
		Mcp\Admin\ExposureController::register();

		// The catalog is the catalog; the MCP server is an optional, off-by-default
		// consumer of it. Boot it only when the gate is on. Both servers run on the
		// MCP Adapter plugin (Mcp\AdapterPlugin): when it is absent or too old,
		// Server::boot() posts one admin notice pointing at its install card and
		// neither server appears — the catalog itself is unaffected.
		if ( ! abilities_catalog_mcp_is_enabled() ) {
			return;
		}

		Mcp\Server::boot();

		// The scalable, search-based server is a second consumer of the same registry,
		// on its own route. It runs the same adapter check and returns silently when
		// Server::boot() has already reported the adapter missing.
		Mcp\SearchServer::boot();
	}
);
