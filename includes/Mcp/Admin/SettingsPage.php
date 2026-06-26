<?php
/**
 * The MCP server settings page in wp-admin.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Settings -> MCP Server page and mounts its React app.
 *
 * This is deliberately thin glue. It adds the menu entry, prints the mount node, and
 * enqueues the front-end on its own screen only; all the logic — reading state,
 * toggling exposure, flipping the enable flag — lives in the React app talking to
 * {@see ExposureController}. The app is built with no bundler: it enqueues WordPress's
 * own `wp-element` (React) and `wp-components` script handles and runs a hand-written
 * script, so the plugin keeps its no-build ethos and ships no compiled asset.
 *
 * The page is registered unconditionally so a site can turn the server on and gate its
 * abilities from one screen, even while the server is off.
 *
 * @since 0.2.0
 */
final class SettingsPage {

	/**
	 * Script handle for the settings app.
	 */
	private const SCRIPT_HANDLE = 'abilities-catalog-mcp-settings';

	/**
	 * Hooks the page registration and asset enqueue onto admin.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'addPage' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Adds the page under the Settings menu.
	 *
	 * @return void
	 */
	public static function addPage(): void {
		add_options_page(
			__( 'Abilities Catalog — MCP Server', 'abilities-catalog' ),
			__( 'MCP Server', 'abilities-catalog' ),
			'manage_options',
			ABILITIES_CATALOG_MCP_SETTINGS_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Prints the mount node the React app renders into.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap" id="abilities-catalog-mcp-settings"></div>';
	}

	/**
	 * Enqueues the settings app, but only on the settings page.
	 *
	 * @param string $hook_suffix The current admin screen's hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . ABILITIES_CATALOG_MCP_SETTINGS_SLUG !== $hook_suffix ) {
			return;
		}

		// Version the no-build asset by its modification time so an edit busts the
		// browser cache on the next reload. The plugin version alone does not change
		// between edits within a release, so a hand-edit to settings.js would otherwise
		// keep serving the cached copy. Falls back to the plugin version.
		$script_path    = ABILITIES_CATALOG_DIR . 'assets/js/settings.js';
		$script_version = file_exists( $script_path )
			? (string) filemtime( $script_path )
			: ABILITIES_CATALOG_VERSION;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/settings.js', ABILITIES_CATALOG_FILE ),
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			$script_version,
			true
		);

		// The app wraps every string in wp.i18n with the plugin text domain; without this
		// the React half of the screen would stay English on every locale while the PHP
		// half translates.
		wp_set_script_translations( self::SCRIPT_HANDLE, 'abilities-catalog' );

		// Inject the full exposure endpoint URL so apiFetch can use `url:` directly,
		// bypassing root URL resolution (which breaks on plain-permalink setups).
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf(
				'var ABILITIES_CATALOG_REST_URL = %s;',
				wp_json_encode(
					add_query_arg(
						'rest_route',
						'/' . ExposureController::REST_NAMESPACE . ExposureController::REST_ROUTE,
						home_url( '/' )
					)
				)
			),
			'before'
		);

		wp_enqueue_style( 'wp-components' );
	}
}
