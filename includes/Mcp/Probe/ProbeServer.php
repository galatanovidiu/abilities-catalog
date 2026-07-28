<?php
/**
 * Throwaway MCP server for mcp-adapter PR #260.
 *
 * @package AbilitiesCatalog
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Mcp\Probe;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Transport\HttpTransport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the probe abilities as first-class MCP tools, resources and prompts.
 *
 * The catalog's own servers ({@see \GalatanOvidiu\AbilitiesCatalog\Mcp\Server} and
 * {@see \GalatanOvidiu\AbilitiesCatalog\Mcp\SearchServer}) reach an ability only
 * through a `discover` / `execute` meta-tool, which re-packages the result before it
 * reaches the wire. PR #260 changes what the adapter's own handlers emit — the
 * `resources/read`, `prompts/get` and typed-content-block paths — and a meta-tool
 * wrapper never enters those. Registering each ability directly on its own server is
 * what puts those handlers in the request path.
 *
 * The server is gated by `ABILITIES_CATALOG_META_PROBE` and sits on its own route, so
 * it neither changes nor shares state with the two real endpoints.
 */
final class ProbeServer {

	/**
	 * Unique server identifier within the adapter's registry.
	 */
	public const SERVER_ID = 'abilities-catalog-probe';

	/**
	 * REST namespace segment for the server endpoint.
	 */
	public const ROUTE_NAMESPACE = 'abilities-catalog/v1';

	/**
	 * REST route segment for the server endpoint.
	 */
	public const ROUTE = 'mcp-probe';

	/**
	 * Reports whether the probe is switched on.
	 *
	 * @return bool True when the probe constant is defined and truthy.
	 */
	public static function isEnabled(): bool {
		return defined( 'ABILITIES_CATALOG_META_PROBE' ) && (bool) constant( 'ABILITIES_CATALOG_META_PROBE' );
	}

	/**
	 * Returns the registered REST route path (namespace + route).
	 *
	 * @return string The route path, e.g. `/abilities-catalog/v1/mcp-probe`.
	 */
	public static function restRoute(): string {
		return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE;
	}

	/**
	 * Registers the probe abilities and hooks server creation.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( ! self::isEnabled() ) {
			return;
		}

		ProbeAbilities::register();

		add_action( 'mcp_adapter_init', array( self::class, 'createServer' ) );
	}

	/**
	 * Creates the probe server on `mcp_adapter_init`.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The adapter announced by the init action.
	 * @return void
	 */
	public static function createServer( McpAdapter $adapter ): void {
		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'Abilities Catalog Probe',
			'Throwaway server exercising mcp-adapter PR #260 _meta and annotations paths.',
			ABILITIES_CATALOG_VERSION,
			array( HttpTransport::class ),
			// A real error handler, not the null default: half of what PR #260 changes
			// is the warning logged when a `_meta` or `annotations` value is dropped,
			// and NullMcpErrorHandler discards exactly those.
			ErrorLogMcpErrorHandler::class,
			null,
			ProbeAbilities::toolNames(),
			ProbeAbilities::resourceNames(),
			ProbeAbilities::promptNames(),
			static fn (): bool => is_user_logged_in()
		);

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Probe-only diagnostics behind WP_DEBUG.
			error_log( '[abilities-catalog probe] server creation failed: ' . $result->get_error_message() );
		}
	}
}
