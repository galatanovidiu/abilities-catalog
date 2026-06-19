<?php
/**
 * Wiring smoke test for the optional MCP server.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Server;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;

/**
 * Proves the Phase 1 boot wiring against a really-booted adapter.
 *
 * Adapter-dependent: it needs the `vendor/` bundle, so it skips when that bundle is
 * absent (the catalog stays dependency-free; this test runs in CI and locally once
 * `composer install` has run). The adapter is a process-wide singleton with no
 * reset, so all booted-server assertions live in one test method that boots exactly
 * once.
 */
final class ServerTest extends TestCase {

	/**
	 * Loads the adapter bundle, or skips the suite when it is not installed.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( McpAdapter::class ) ) {
			$autoload = TESTS_REPO_ROOT_DIR . '/vendor/autoload_packages.php';
			if ( is_readable( $autoload ) ) {
				require_once $autoload;
			}
		}

		if ( ! class_exists( McpAdapter::class ) ) {
			$this->markTestSkipped( 'The mcp-adapter vendor bundle is not installed; run composer install.' );
		}
	}

	/**
	 * Drops the default-server suppression filter this test installs.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'mcp_adapter_create_default_server', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Booting registers the server's REST route, suppresses the adapter's default
	 * server, and stores a real McpServer (so `create_server()` did not error).
	 *
	 * @return void
	 */
	public function test_booting_registers_route_and_suppresses_default_server(): void {
		( new Server() )->register();

		// Rebuild the REST server so the adapter's init chain runs against a fresh
		// route table: register() hooked McpAdapter::init() onto rest_api_init, which
		// fires mcp_adapter_init -> Server::createServer() -> create_server(); the
		// HttpTransport then registers its route on the same rest_api_init pass.
		global $wp_rest_server;
		$wp_rest_server = null;
		$routes         = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			Server::restRoute(),
			$routes,
			'The MCP REST route should be registered when the server is booted.'
		);
		$this->assertArrayNotHasKey(
			'/mcp/mcp-adapter-default-server',
			$routes,
			"The adapter's default server should be suppressed."
		);

		$server = McpAdapter::instance()->get_server( Server::SERVER_ID );
		$this->assertInstanceOf(
			McpServer::class,
			$server,
			'create_server() should have stored our server (i.e. returned no WP_Error).'
		);
	}
}
