<?php
/**
 * Plugin Name:       Abilities Catalog
 * Plugin URI:        https://github.com/galatanovidiu/abilities-catalog
 * Description:       Registers WordPress wp-admin features as Abilities API abilities, plus an optional, off-by-default built-in MCP server that exposes them as curated domain tools. Consumer-agnostic: the catalog works standalone, and any Abilities API consumer can expose the abilities.
 * Version:           0.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Ovidiu Galatan
 * Author URI:        https://github.com/galatanovidiu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       abilities-catalog
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABILITIES_CATALOG_VERSION', '0.2.0' );
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
		( new Registry() )->register();

		// The Abilities API ships with WordPress 7.0; without it the MCP layer has
		// nothing to expose, so skip all of it (the catalog above already no-ops).
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// The MCP server's settings page and its exposure REST API are always
		// available — independent of whether the server is on — so a site can turn the
		// server on and gate its abilities from one screen in wp-admin.
		Mcp\Admin\SettingsPage::register();
		Mcp\Admin\ExposureController::register();

		// The catalog is the catalog; the MCP server is an optional, off-by-default
		// consumer of it. Boot it only when the gate is on.
		if ( ! abilities_catalog_mcp_is_enabled() ) {
			return;
		}

		Mcp\Server::boot();
	}
);
