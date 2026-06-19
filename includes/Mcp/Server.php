<?php
/**
 * Boots the optional, off-by-default MCP server.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings up the plugin's own MCP server on top of the bundled mcp-adapter.
 *
 * The catalog registers its abilities regardless of this class; the server is an
 * optional consumer that exposes them over the Model Context Protocol. {@see boot()}
 * is the single entry point the bootstrap calls when the gate
 * ({@see abilities_catalog_mcp_is_enabled()}) is on. The class owns the one failure
 * mode the gate cannot — "enabled but the adapter vendor bundle is absent" — and
 * turns it into a loud-but-safe admin notice instead of a fatal.
 *
 * Phase 1 stands up an empty server: it proves the wiring (the REST endpoint
 * appears when enabled, the adapter's default server is suppressed) without yet
 * registering any domain tools. Later phases fill the `$tools` array.
 *
 * @since 0.2.0
 */
final class Server {

	/**
	 * Unique server identifier within the adapter's registry.
	 */
	public const SERVER_ID = 'abilities-catalog';

	/**
	 * REST namespace segment for the server endpoint.
	 */
	public const ROUTE_NAMESPACE = 'abilities-catalog/v1';

	/**
	 * REST route segment for the server endpoint.
	 */
	public const ROUTE = 'mcp';

	/**
	 * Returns the registered REST route path (namespace + route).
	 *
	 * The MCP endpoint lives at `/wp-json` followed by this path. Exposed so tests
	 * and docs name the route in one place instead of hard-coding the string.
	 *
	 * @return string The route path, e.g. `/abilities-catalog/v1/mcp`.
	 */
	public static function restRoute(): string {
		return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE;
	}

	/**
	 * Brings up the server, or reports missing dependencies.
	 *
	 * Called only when the gate is on. When the adapter vendor bundle is present it
	 * loads it and registers the server wiring; when it is absent it registers an
	 * admin notice plus a WP_DEBUG log line and returns without booting — the catalog
	 * keeps working, the server simply does not appear.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$autoload = ABILITIES_CATALOG_DIR . 'vendor/autoload_packages.php';

		if ( ! is_readable( $autoload ) ) {
			self::reportMissingDependencies();

			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path built from a plugin constant and an internal literal, not user input.
		require_once $autoload;

		if ( ! class_exists( McpAdapter::class ) ) {
			self::reportMissingDependencies();

			return;
		}

		( new self() )->register();
	}

	/**
	 * Registers the server wiring on the adapter's init cycle.
	 *
	 * Three steps, all safe to run on `plugins_loaded` (before the adapter's own
	 * `rest_api_init`/`init` pass):
	 * 1. Suppress the adapter's default server so only this server is exposed.
	 * 2. Initialize the adapter singleton, which hooks its init onto the request
	 *    lifecycle.
	 * 3. Hook {@see createServer()} onto `mcp_adapter_init`, the action where
	 *    server creation is required to happen.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		McpAdapter::instance();

		add_action( 'mcp_adapter_init', array( $this, 'createServer' ) );
	}

	/**
	 * Creates the (Phase 1: empty) custom server on `mcp_adapter_init`.
	 *
	 * `create_server()` returns the adapter on success or a `WP_Error`; it never
	 * throws. A failure is logged under WP_DEBUG and otherwise swallowed so a server
	 * that cannot boot never breaks the catalog.
	 *
	 * The transport permission callback is a coarse floor ("may this user reach the
	 * server at all"). It is not the real authorization gate: every `execute` still
	 * runs the target ability's own `permission_callback`.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter announced by the init action.
	 * @return void
	 */
	public function createServer( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'Abilities Catalog', 'abilities-catalog' ),
			__( 'WordPress wp-admin abilities exposed as curated MCP domain tools.', 'abilities-catalog' ),
			ABILITIES_CATALOG_VERSION,
			array( HttpTransport::class ),
			null,
			null,
			array(),
			array(),
			array(),
			static fn (): bool => is_user_logged_in()
		);

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		self::log( 'MCP server creation failed: ' . $result->get_error_message() );
	}

	/**
	 * Registers the "enabled but dependencies missing" admin notice and log line.
	 *
	 * Fail loud-but-safe: a capable admin sees why the server did not appear and a
	 * WP_DEBUG log records it, but nothing fatals and no half-server boots.
	 *
	 * @return void
	 */
	private static function reportMissingDependencies(): void {
		self::log( 'MCP server is enabled but its dependencies are not installed. Run "composer install" in the plugin directory, or use the release build that bundles them.' );

		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				wp_admin_notice(
					esc_html__( 'The Abilities Catalog MCP server is enabled, but its dependencies are not installed. Run "composer install" in the plugin directory, or use the release build that bundles them.', 'abilities-catalog' ),
					array(
						'type'        => 'error',
						'dismissible' => false,
					)
				);
			}
		);
	}

	/**
	 * Logs a server diagnostic, but only under WP_DEBUG.
	 *
	 * @param string $message The diagnostic message.
	 * @return void
	 */
	private static function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-guarded diagnostic; an optional feature that failed to boot has no other channel.
		error_log( 'Abilities Catalog: ' . $message );
	}
}
