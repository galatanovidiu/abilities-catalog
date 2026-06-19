<?php
/**
 * Tests for the abilities_catalog_mcp_tools custom-tool merge.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Server;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Domain\Tools\McpTool;

/**
 * The merge lets a third party ship a whole tool: a custom tool is appended, a name
 * collision with a curated tool replaces it (custom wins, with a notice), a non-tool
 * value is skipped, and a non-array filter result falls back to the curated set.
 *
 * Adapter-dependent (it builds real McpTool instances), so it skips when the vendor
 * bundle is absent — like {@see ServerTest}.
 */
final class ServerCustomToolsTest extends TestCase {

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
	 * A custom tool with a fresh name is appended to the curated set.
	 *
	 * @return void
	 */
	public function test_appends_custom_tool(): void {
		$content = $this->tool( 'content' );
		$custom  = $this->tool( 'demo-tool' );

		$merged = Server::mergeCustomTools( array( $content ), array( $content, $custom ) );

		$this->assertSame( array( 'content', 'demo-tool' ), $this->names( $merged ) );
	}

	/**
	 * A custom tool wins a name collision with a curated tool and a notice fires.
	 *
	 * @return void
	 */
	public function test_custom_tool_wins_curated_collision(): void {
		$this->setExpectedIncorrectUsage( Server::class . '::mergeCustomTools' );

		$curated = $this->tool( 'content' );
		$custom  = $this->tool( 'content' );

		$merged = Server::mergeCustomTools( array( $curated ), array( $curated, $custom ) );

		$this->assertCount( 1, $merged );
		$this->assertSame( $custom, $merged[0], 'The custom tool should replace the curated one.' );
	}

	/**
	 * A filter that returns only custom tools still keeps the curated ones.
	 *
	 * The curated set is seeded first, so a replace-style filter cannot silently drop a
	 * curated tool — it can only override a curated slot (with a notice) or add to the set.
	 *
	 * @return void
	 */
	public function test_replacing_filter_keeps_curated_tools(): void {
		$this->setExpectedIncorrectUsage( Server::class . '::mergeCustomTools' );

		$content = $this->tool( 'content' );
		$media   = $this->tool( 'media' );
		$custom  = $this->tool( 'content' );

		$merged = Server::mergeCustomTools( array( $content, $media ), array( $custom ) );

		$this->assertSame( array( 'content', 'media' ), $this->names( $merged ) );
		$this->assertSame( $custom, $merged[0], 'The custom tool should win the curated "content" slot.' );
		$this->assertSame( $media, $merged[1], 'The untouched curated "media" tool must survive.' );
	}

	/**
	 * A non-McpTool value in the filter result is skipped.
	 *
	 * @return void
	 */
	public function test_non_tool_value_is_skipped(): void {
		$content = $this->tool( 'content' );
		$custom  = $this->tool( 'demo-tool' );

		$merged = Server::mergeCustomTools( array( $content ), array( $content, 'not-a-tool', $custom ) );

		$this->assertSame( array( 'content', 'demo-tool' ), $this->names( $merged ) );
	}

	/**
	 * A non-array filter result falls back to the curated set.
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_falls_back(): void {
		$content = $this->tool( 'content' );

		$merged = Server::mergeCustomTools( array( $content ), 'broken' );

		$this->assertSame( array( $content ), $merged );
	}

	/**
	 * Builds a minimal MCP tool with the given name.
	 *
	 * @param string $name The tool name.
	 * @return \WP\MCP\Domain\Tools\McpTool The tool.
	 */
	private function tool( string $name ): McpTool {
		$tool = McpTool::fromArray(
			array(
				'name'        => $name,
				'description' => 'Test tool.',
				'inputSchema' => array( 'type' => 'object' ),
				'handler'     => static fn ( array $args ): array => array( 'ok' => true ),
				'permission'  => static fn (): bool => true,
			)
		);

		$this->assertInstanceOf( McpTool::class, $tool );

		return $tool;
	}

	/**
	 * Returns the names of the given tools, in order.
	 *
	 * @param list<\WP\MCP\Domain\Tools\McpTool> $tools The tools.
	 * @return list<string> The tool names.
	 */
	private function names( array $tools ): array {
		return array_map(
			static fn ( McpTool $tool ): string => $tool->get_protocol_dto()->getName(),
			$tools
		);
	}
}
