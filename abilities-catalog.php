<?php
/**
 * Plugin Name:       Abilities Catalog
 * Plugin URI:        https://github.com/galatanovidiu/abilities-catalog
 * Description:       Registers WordPress wp-admin features as Abilities API abilities. Platform-agnostic: it only registers abilities and does not touch any browser bridge. A WebMCP adapter (or any Abilities API consumer) can expose them as tools.
 * Version:           0.1.0
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

define( 'ABILITIES_CATALOG_VERSION', '0.1.0' );
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
	static function ( string $class ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = ABILITIES_CATALOG_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! is_readable( $path ) ) {
			return;
		}

		require_once $path;
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		( new Registry() )->register();
	}
);
