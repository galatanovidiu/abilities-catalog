<?php
/**
 * Wiring test for the scalable, search-based MCP server.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\SearchServer;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP\MCP\Domain\Tools\McpTool;

/**
 * Proves the search server boots, registers its route, and exposes its four bounded
 * discovery tools plus the standalone knowledge tool.
 *
 * Adapter-dependent: it needs the `vendor/` bundle, so it skips when that bundle is
 * absent. The adapter is a process-wide singleton with no reset, so the whole wiring is
 * asserted in one method that boots exactly once.
 */
final class SearchServerTest extends TestCase {

	use ResetsMcpAdapter;

	/**
	 * Every tool the search server must expose: the four discovery tools plus knowledge.
	 *
	 * @var list<string>
	 */
	private const SEARCH_TOOLS = array( 'overview', 'search-abilities', 'describe-ability', 'execute-ability', 'knowledge' );

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

		// The adapter is a one-boot-per-process singleton; reset it so this test boots it
		// fresh even when another booting test (e.g. ServerTest) ran first. See the trait.
		$this->resetMcpAdapter();
	}

	/**
	 * Drops the test-only default-server suppression filter.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'mcp_adapter_create_default_server', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Booting registers the search route, exposes the four discovery tools plus the
	 * standalone knowledge tool, and runs the knowledge tool end-to-end.
	 *
	 * The knowledge tool is the same standalone tool the curated {@see Server} exposes
	 * (both build it from {@see KnowledgeToolFactory}), not folded into the discovery
	 * surface — so its `{uri?}` round-trip behaves identically here.
	 *
	 * @return void
	 */
	public function test_booting_exposes_discovery_tools_and_standalone_knowledge(): void {
		// The default server boots lazily here, after wp_abilities_api_init has already
		// fired, so suppress it to avoid a _doing_it_wrong over its missing tools (this
		// mirrors ServerTest; production boots the adapter early enough that it is fine).
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		( new SearchServer() )->register();

		// Rebuild the REST server so the adapter's init chain runs: register() hooked
		// McpAdapter::init() onto rest_api_init, which fires mcp_adapter_init ->
		// SearchServer::createServer() -> create_server(); HttpTransport then registers
		// the route on the same pass.
		global $wp_rest_server;
		$wp_rest_server = null;
		$routes         = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			SearchServer::restRoute(),
			$routes,
			'The search MCP REST route should be registered when the server is booted.'
		);

		$server = McpAdapter::instance()->get_server( SearchServer::SERVER_ID );
		$this->assertInstanceOf(
			McpServer::class,
			$server,
			'create_server() should have stored the search server (i.e. returned no WP_Error).'
		);

		$tools = $server->get_tools();
		foreach ( self::SEARCH_TOOLS as $name ) {
			$this->assertArrayHasKey( $name, $tools, sprintf( 'The "%s" tool should be registered on the search server.', $name ) );
		}
		$this->assertCount(
			count( self::SEARCH_TOOLS ),
			$tools,
			'The search server should expose exactly its four discovery tools plus the knowledge tool.'
		);

		// The shared permission floor refuses a logged-out caller.
		wp_set_current_user( 0 );
		$this->assertFalse( $server->get_mcp_tool( 'overview' )->check_permission( array() ) );

		// End-to-end knowledge round-trip: no uri returns the index, a uri returns one
		// concept's body. Both need no ability capability, only the shared floor.
		$this->actingAs( 'administrator' );
		$knowledge = $server->get_mcp_tool( 'knowledge' );
		$this->assertInstanceOf( McpTool::class, $knowledge );

		$index = $knowledge->execute( array() );
		$this->assertIsArray( $index );
		$this->assertArrayHasKey( 'index', $index );
		$this->assertStringContainsString( 'core/create-content', $index['index'] );

		$concept = $knowledge->execute( array( 'uri' => 'core/create-content' ) );
		$this->assertIsArray( $concept );
		$this->assertArrayHasKey( 'concept', $concept );
		$this->assertSame( 'core/create-content', $concept['concept']['uri'] );
		$this->assertNotEmpty( $concept['concept']['body'] );
	}
}
