<?php
/**
 * Integration tests for the domain-tool MCP shim.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp;

use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainMap;
use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainRouter;
use GalatanOvidiu\AbilitiesCatalog\Mcp\DomainToolHandler;
use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Verifies the shim's three jobs: dispatch the `action`, shape success results as
 * objects, and fold error code + status into the `WP_Error` message (since the
 * adapter surfaces only the message).
 */
final class DomainToolHandlerTest extends TestCase {

	/**
	 * The content-domain handler under test.
	 *
	 * @var \GalatanOvidiu\AbilitiesCatalog\Mcp\DomainToolHandler
	 */
	private DomainToolHandler $handler;

	/**
	 * Builds a content-domain handler over the real router for each test.
	 *
	 * The exposure gate is deny-by-default, so the abilities these tests execute are
	 * enabled here; the gate's own behavior is covered by {@see DomainRouterTest}. The
	 * shim under test is unaffected by which abilities are enabled.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION, array( 'content/get-post', 'content/create-post' ) );
		$this->handler = new DomainToolHandler( new DomainRouter( new DomainMap(), new ExposurePolicy() ), 'content' );
	}

	/**
	 * Clears the exposure option after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		parent::tear_down();
	}

	/**
	 * The list action wraps the ability index in an object.
	 *
	 * @return void
	 */
	public function test_list_action_wraps_abilities_in_object(): void {
		$result = $this->handler->handle( array( 'action' => 'list' ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'abilities', $result );
		$this->assertNotEmpty( $result['abilities'] );
	}

	/**
	 * The describe action returns the description object.
	 *
	 * @return void
	 */
	public function test_describe_action_returns_object(): void {
		$result = $this->handler->handle( array( 'action' => 'describe', 'ability' => 'content/get-post' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'content/get-post', $result['name'] );
	}

	/**
	 * The execute action runs the ability for a capable user.
	 *
	 * @return void
	 */
	public function test_execute_action_runs_ability(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create();

		$result = $this->handler->handle(
			array(
				'action'  => 'execute',
				'ability' => 'content/get-post',
				'input'   => array( 'id' => $post_id ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
	}

	/**
	 * An unknown action folds into an error carrying the status.
	 *
	 * @return void
	 */
	public function test_unknown_action_is_a_folded_error(): void {
		$result = $this->handler->handle( array( 'action' => 'frobnicate' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_invalid_action', $result->get_error_code() );
		$this->assertStringContainsString( 'status: 400', $result->get_error_message() );
	}

	/**
	 * A missing action is treated as unknown.
	 *
	 * @return void
	 */
	public function test_missing_action_is_a_folded_error(): void {
		$result = $this->handler->handle( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_invalid_action', $result->get_error_code() );
	}

	/**
	 * An out-of-domain ability folds into a 404 error.
	 *
	 * @return void
	 */
	public function test_out_of_domain_execute_is_a_folded_error(): void {
		$result = $this->handler->handle( array( 'action' => 'execute', 'ability' => 'media/list-image-sizes' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_unknown_ability', $result->get_error_code() );
		$this->assertStringContainsString( 'status: 404', $result->get_error_message() );
	}

	/**
	 * A present-but-non-object `input` folds into a 400 error instead of being dropped.
	 *
	 * @return void
	 */
	public function test_non_object_input_is_a_folded_error(): void {
		$this->actingAs( 'administrator' );

		$result = $this->handler->handle(
			array(
				'action'  => 'execute',
				'ability' => 'content/get-post',
				'input'   => 'not-an-object',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'abilities_catalog_mcp_invalid_input', $result->get_error_code() );
		$this->assertStringContainsString( 'status: 400', $result->get_error_message() );
	}

	/**
	 * A denied execute folds into an error whose message keeps the code and status.
	 *
	 * @return void
	 */
	public function test_denied_execute_folds_code_and_status_into_message(): void {
		$this->actingAs( 'subscriber' );

		$result = $this->handler->handle(
			array(
				'action'  => 'execute',
				'ability' => 'content/create-post',
				'input'   => array( 'title' => 'Nope' ),
			)
		);

		$this->assertWPError( $result );
		// The router pre-checks the capability and returns its own 'forbidden' (403),
		// consistent with the other MCP execute paths; the handler folds that into the message.
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertStringContainsString( 'forbidden', $result->get_error_message() );
		$this->assertStringContainsString( 'status: 403', $result->get_error_message() );
	}
}
