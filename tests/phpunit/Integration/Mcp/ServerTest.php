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
use WP\MCP\Domain\Tools\McpTool;

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

	use ResetsMcpAdapter;

	/**
	 * The curated domain slugs the server must expose as tools, in spec order.
	 *
	 * @var list<string>
	 */
	private const CURATED_DOMAINS = array( 'content', 'media', 'appearance', 'design', 'plugins', 'users', 'settings', 'tools', 'site-health', 'updates', 'dashboard', 'network' );

	/**
	 * Loads the adapter bundle, or skips the suite when it is not installed.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// The exposure gate is deny-by-default; enable the one ability the end-to-end
		// execute round-trip runs so the gate lets it through to the capability check.
		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array( 'content/get-post' ) );

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
		// fresh even when another booting test (e.g. SearchServerTest) ran first. See the trait.
		$this->resetMcpAdapter();
	}

	/**
	 * Drops the test-only default-server suppression filter and the exposure option.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'mcp_adapter_create_default_server', '__return_false' );
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		parent::tear_down();
	}

	/**
	 * Booting registers the REST route, exposes every curated domain tool, publishes
	 * the owner-enabled abilities to the adapter's default server, and runs an ability
	 * end-to-end through one of them.
	 *
	 * The adapter is a process-wide singleton with no reset, so this boots exactly
	 * once and asserts the whole wiring in one method.
	 *
	 * @return void
	 */
	public function test_booting_wires_domain_tools_route(): void {
		// Test-only: the adapter boots lazily here, after wp_abilities_api_init has
		// already fired, so the default server's own abilities are never registered.
		// Suppress it so the adapter does not _doing_it_wrong building a server with
		// missing tools. Production boots the adapter on plugins_loaded, before that
		// hook, so the default server creates cleanly and is intentionally left enabled.
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		( new Server() )->register();

		// Booting also publishes the curated, owner-enabled abilities to the bundled
		// adapter's default server by marking them mcp.public through the
		// wp_register_ability_args filter. Assert that filter directly (it does not
		// depend on registration timing): only an ability that is BOTH curated (mapped
		// to a domain) AND enabled in the exposure gate is published, so the second
		// endpoint honors the same deny-by-default gate as the curated server. set_up()
		// enabled only content/get-post.
		$enabled_mapped = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), 'content/get-post' );
		$this->assertTrue(
			$enabled_mapped['meta']['mcp']['public'] ?? false,
			'A curated, exposure-enabled ability should be marked mcp.public for the default server.'
		);

		$disabled_mapped = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), 'content/create-post' );
		$this->assertArrayNotHasKey(
			'mcp',
			$disabled_mapped['meta'],
			'A curated but exposure-disabled ability must not be public on the default server (the gate holds).'
		);

		$unmapped = apply_filters( 'wp_register_ability_args', array( 'meta' => array() ), 'nonexistent/thing' );
		$this->assertArrayNotHasKey(
			'mcp',
			$unmapped['meta'],
			'An unmapped ability must not be exposed on the default server.'
		);

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

		$server = McpAdapter::instance()->get_server( Server::SERVER_ID );
		$this->assertInstanceOf(
			McpServer::class,
			$server,
			'create_server() should have stored our server (i.e. returned no WP_Error).'
		);

		// The server exposes one curated tool per domain, not flat ability tools, plus
		// the one cross-cutting knowledge tool.
		$tools = $server->get_tools();
		foreach ( self::CURATED_DOMAINS as $slug ) {
			$this->assertArrayHasKey( $slug, $tools, sprintf( 'The "%s" domain tool should be registered.', $slug ) );
		}
		$this->assertArrayHasKey( 'knowledge', $tools, 'The cross-cutting knowledge tool should be registered.' );
		$this->assertCount(
			count( self::CURATED_DOMAINS ) + 1,
			$tools,
			'The server should expose exactly the curated domain tools plus the knowledge tool.'
		);

		// Each curated domain carries its own hand-written blurb, never the generic
		// "third-party domain" fallback — a forgotten case would ship that fallback as
		// a real tool's routing description.
		foreach ( self::CURATED_DOMAINS as $slug ) {
			$description = $server->get_mcp_tool( $slug )->get_protocol_dto()->getDescription();
			$this->assertStringNotContainsString(
				'another plugin contributed',
				(string) $description,
				sprintf( 'The "%s" domain tool is missing its curated description (it fell back to the generic blurb).', $slug )
			);
		}

		$tool = $server->get_mcp_tool( 'content' );
		$this->assertInstanceOf( McpTool::class, $tool );

		// The shared permission floor refuses a logged-out caller.
		wp_set_current_user( 0 );
		$this->assertFalse( $tool->check_permission( array( 'action' => 'list' ) ) );

		// End-to-end execute: adapter McpTool -> DomainToolHandler -> DomainRouter -> ability.
		$admin   = $this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_author' => $admin ) );

		$result = $tool->execute(
			array(
				'action'  => 'execute',
				'ability' => 'content/get-post',
				'input'   => array( 'id' => $post_id ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );

		// End-to-end knowledge round-trip: no uri returns the index, a uri returns one
		// concept's body. Both need no ability capability, only the shared floor.
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
