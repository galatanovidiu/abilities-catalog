<?php
/**
 * Tests for the mcp_adapter_tool_call_result list-result guard.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\Server;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * A top-level list result is wrapped as `{items: [...]}` so it is a valid MCP
 * structuredContent object; an object, a WP_Error, and non-array values pass through.
 *
 * Pure logic (it reads no filter args and no adapter), so it runs without the vendor
 * bundle — unlike {@see ServerTest}, which proves the same guard wired onto the adapter.
 */
final class ObjectifyResultTest extends TestCase {

	public function test_wraps_a_top_level_list(): void {
		$this->assertSame(
			array( 'items' => array( 1, 2, 3 ) ),
			Server::objectifyResult( array( 1, 2, 3 ) )
		);
	}

	public function test_wraps_an_empty_list(): void {
		// The failing real case (an empty Yoast score list) must be wrapped too: a bare
		// `[]` is still a JSON array, which is invalid as structuredContent.
		$this->assertSame(
			array( 'items' => array() ),
			Server::objectifyResult( array() )
		);
	}

	public function test_leaves_an_object_result_untouched(): void {
		$object = array(
			'items' => array( 1, 2 ),
			'total' => 2,
		);

		$this->assertSame( $object, Server::objectifyResult( $object ) );
	}

	public function test_leaves_a_wp_error_untouched(): void {
		$error = new WP_Error( 'nope', 'No.' );

		$this->assertSame( $error, Server::objectifyResult( $error ) );
	}

	public function test_leaves_a_scalar_untouched(): void {
		$this->assertSame( 'plain', Server::objectifyResult( 'plain' ) );
		$this->assertNull( Server::objectifyResult( null ) );
	}
}
