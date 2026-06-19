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
 * The server registers one curated tool per domain — the full set the
 * {@see DomainMap} lists, plus any domain a third party opens through the
 * `abilities_catalog_mcp_domain_map` filter. Each domain tool routes its `list` /
 * `describe` / `execute` actions through a {@see DomainRouter}; the REST endpoint
 * appears only when the gate is on, and the adapter's own default server is
 * suppressed so only this server is exposed.
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
	 * Creates the custom server, with its curated domain tools, on `mcp_adapter_init`.
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
			$this->tools(),
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
	 * Builds one domain tool per slug the map lists.
	 *
	 * Iterates the {@see DomainMap} so the curated domains and any a third party
	 * opens through the `abilities_catalog_mcp_domain_map` filter are all exposed. A
	 * tool the adapter rejects is logged and skipped rather than aborting the whole
	 * server, so one bad domain never costs the others. Before building, it asks the
	 * map to warn about any registered ability no domain owns (so it cannot be
	 * silently unexposed).
	 *
	 * @return list<\WP\MCP\Domain\Tools\McpTool> The domain tools to register.
	 */
	private function tools(): array {
		$map = new DomainMap();
		$map->reportUnmapped( array_map( 'strval', array_keys( wp_get_abilities() ) ) );

		$factory = new DomainToolFactory( new DomainRouter( $map ), $this->toolPermission() );

		$tools = array();
		foreach ( $map->domains() as $domain ) {
			$tool = $factory->forDomain( $domain, $this->description( $domain ) );
			if ( is_wp_error( $tool ) ) {
				self::log( sprintf( 'Failed to build the "%s" domain tool: %s', $domain, $tool->get_error_message() ) );

				continue;
			}

			$tools[] = $tool;
		}

		return $tools;
	}

	/**
	 * Resolves the coarse permission floor every domain tool shares.
	 *
	 * This is not the real authorization gate — every `execute` still runs the
	 * target ability's own `permission_callback`. It is a filterable "may this user
	 * reach the server at all" floor; it defaults to any logged-in user.
	 *
	 * @return callable The permission floor, `fn(array $args): bool|WP_Error`.
	 */
	private function toolPermission(): callable {
		/**
		 * Filters the coarse permission floor every domain tool shares.
		 *
		 * @since 0.2.0
		 *
		 * @param callable $permission A `fn(array $args): bool|WP_Error` floor. Default: any logged-in user.
		 */
		$permission = apply_filters( 'abilities_catalog_mcp_tool_permission', static fn (): bool => is_user_logged_in() );

		return is_callable( $permission ) ? $permission : static fn (): bool => is_user_logged_in();
	}

	/**
	 * The full hand-written description for a domain tool.
	 *
	 * A domain's capability blurb (the scope an agent routes on) followed by the
	 * shared action protocol (the three actions every domain tool answers). The blurb
	 * is per-domain; the protocol line is identical across tools, so it lives once.
	 *
	 * @param string $domain The domain slug.
	 * @return string The tool description.
	 */
	private function description( string $domain ): string {
		return $this->domainBlurb( $domain ) . ' ' . __( 'Call "list" to see abilities, "describe" for a schema, "execute" to run one.', 'abilities-catalog' );
	}

	/**
	 * The hand-written capability blurb for one domain (spec §6, §11).
	 *
	 * Short scope statements so an agent can route to a domain without listing its
	 * abilities. A domain a third party opens through the
	 * `abilities_catalog_mcp_domain_map` filter has no curated blurb, so it gets a
	 * generic one.
	 *
	 * @param string $domain The domain slug.
	 * @return string The capability blurb, without the shared action protocol line.
	 */
	private function domainBlurb( string $domain ): string {
		switch ( $domain ) {
			case 'content':
				return __( 'Manage content — full CRUD on posts, pages and all custom post types; categories and tags; comments; post meta and revisions; content search.', 'abilities-catalog' );
			case 'media':
				return __( 'Manage the media library — upload, list, read, update and delete attachments; edit and crop images; regenerate thumbnails; and read the registered image sizes.', 'abilities-catalog' );
			case 'appearance':
				return __( 'Manage site appearance — install, switch, list and delete themes (and search the theme directory); and create, update and delete classic menus and block navigation, their items, and menu locations.', 'abilities-catalog' );
			case 'design':
				return __( 'Manage site design — create, read, update and delete block templates and template parts; create and read block patterns and synced patterns; read and update global and theme styles; list registered block types; and install, list and delete web fonts and font collections.', 'abilities-catalog' );
			case 'plugins':
				return __( 'Manage plugins — list, read, install (from the plugin directory), activate, deactivate, update and delete plugins, and search the plugin directory.', 'abilities-catalog' );
			case 'users':
				return __( 'Manage users — create, list, read, update and delete user accounts; read and update the current user; and manage application passwords.', 'abilities-catalog' );
			case 'settings':
				return __( 'Manage site settings — read and update the general, writing, reading, discussion, media, permalink and privacy option groups; read or update a single named option; and read connector (integration provider) metadata.', 'abilities-catalog' );
			case 'tools':
				return __( 'Site tools — export site content (WXR), list the available content importers, and manage personal-data export and erasure requests.', 'abilities-catalog' );
			case 'site-health':
				return __( 'Inspect Site Health — read the status report, run the health tests, and read the debug information.', 'abilities-catalog' );
			case 'updates':
				return __( 'Manage updates — list the available core, plugin, theme and translation updates, and run a plugin, theme or translation update.', 'abilities-catalog' );
			case 'dashboard':
				return __( 'Read the dashboard — recent site activity, the At a Glance counts, and recent drafts.', 'abilities-catalog' );
			default:
				return sprintf(
					/* translators: %s: domain slug. */
					__( 'The "%s" domain — abilities another plugin contributed to this server.', 'abilities-catalog' ),
					$domain
				);
		}
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
