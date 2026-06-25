<?php
/**
 * Per-test reset for the process-wide MCP adapter singleton.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use ReflectionProperty;
use WP\MCP\Core\McpAdapter;

/**
 * Lets more than one test boot the adapter in the same process.
 *
 * {@see McpAdapter} is a process-wide singleton built for one boot per request: it
 * fires `mcp_adapter_init` (where servers are created) exactly once, guarded by a
 * static `initialized` flag, and binds `init()` onto `rest_api_init` only the first
 * time {@see McpAdapter::instance()} builds the instance. In a suite that runs more
 * than one booting test, the second test inherits a singleton whose servers map is
 * already populated and whose `rest_api_init` binding the WordPress test harness has
 * since restored away — so its server is never (re)created and its route never appears.
 *
 * Calling {@see resetMcpAdapter()} from `set_up()` (after the vendor-bundle guard)
 * makes each booting test start from the same clean state the lone booting test gets
 * on its own: empty servers map, init guard down, and `init()` bound to the next
 * `rest_api_init` pass the test triggers by rebuilding `$wp_rest_server`.
 */
trait ResetsMcpAdapter {

	/**
	 * Resets the adapter singleton so this test boots it fresh, order-independent.
	 *
	 * @return void
	 */
	protected function resetMcpAdapter(): void {
		$adapter = McpAdapter::instance();

		// Empty the servers map a prior booting test left behind, so create_server()
		// does not reject this test's server as a duplicate id (and _doing_it_wrong).
		$servers = new ReflectionProperty( McpAdapter::class, 'servers' );
		$servers->setAccessible( true );
		$servers->setValue( $adapter, array() );

		// Drop the once-only init guard so init() fires mcp_adapter_init again.
		$initialized = new ReflectionProperty( McpAdapter::class, 'initialized' );
		$initialized->setAccessible( true );
		$initialized->setValue( null, false );

		// Re-add the rest_api_init -> init binding instance() installs only on first
		// build; the harness restores hooks between tests, so a later test would
		// otherwise fire rest_api_init without ever running init().
		if ( ! has_action( 'rest_api_init', array( $adapter, 'init' ) ) ) {
			add_action( 'rest_api_init', array( $adapter, 'init' ), 15 );
		}
	}
}
