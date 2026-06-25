<?php
/**
 * Integration tests for the exposure REST controller.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Mcp\Admin;

use GalatanOvidiu\AbilitiesCatalog\Mcp\ExposurePolicy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The settings REST route reads and writes exposure under `manage_options`: it reports
 * the deny-by-default state, persists toggles, drops forged ability names, flips the
 * server enable flag, and refuses a non-admin entirely.
 */
final class ExposureControllerTest extends TestCase {

	/**
	 * The REST server the requests dispatch through.
	 *
	 * @var \WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Boots a fresh REST server so the plugin's route is registered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	/**
	 * Tears down the REST server and the options each test may have written.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( ABILITIES_CATALOG_MCP_EXPOSED_OPTION );
		delete_option( ABILITIES_CATALOG_MCP_ENABLED_OPTION );
		parent::tear_down();
	}

	/**
	 * An administrator gets the full deny-by-default state.
	 *
	 * @return void
	 */
	public function test_get_returns_state_for_admin(): void {
		$this->actingAs( 'administrator' );

		$response = $this->dispatch( 'GET' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'server_enabled', $data );
		$this->assertArrayHasKey( 'endpoint', $data );
		$this->assertArrayHasKey( 'domains', $data );
		$this->assertNotEmpty( $data['domains'] );
		$this->assertFalse( $this->abilityEnabled( $data, 'og-content/get-post' ), 'Abilities are disabled by default.' );
	}

	/**
	 * A subscriber may not read the exposure state.
	 *
	 * @return void
	 */
	public function test_get_is_forbidden_for_subscriber(): void {
		$this->actingAs( 'subscriber' );

		$this->assertSame( 403, $this->dispatch( 'GET' )->get_status() );
	}

	/**
	 * Enabling an ability persists it and shows up in the returned state.
	 *
	 * @return void
	 */
	public function test_post_enables_an_ability(): void {
		$this->actingAs( 'administrator' );

		$response = $this->dispatch( 'POST', array( 'abilities' => array( 'og-content/get-post' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'og-content/get-post', ExposurePolicy::stored() );
		$this->assertTrue( $this->abilityEnabled( $response->get_data(), 'og-content/get-post' ) );
	}

	/**
	 * A forged ability name is never stored.
	 *
	 * @return void
	 */
	public function test_post_drops_unknown_ability_name(): void {
		$this->actingAs( 'administrator' );

		$this->dispatch( 'POST', array( 'abilities' => array( 'plugins/forged-name' => true ) ) );

		$this->assertNotContains( 'plugins/forged-name', ExposurePolicy::stored() );
	}

	/**
	 * The server enable flag flips through the option (no constant locking it here).
	 *
	 * @return void
	 */
	public function test_post_toggles_server_enable_flag(): void {
		$this->actingAs( 'administrator' );

		$this->dispatch( 'POST', array( 'server_enabled' => true ) );
		$this->assertTrue( abilities_catalog_mcp_is_enabled() );

		$this->dispatch( 'POST', array( 'server_enabled' => false ) );
		$this->assertFalse( abilities_catalog_mcp_is_enabled() );
	}

	/**
	 * A subscriber cannot write exposure, and nothing is stored.
	 *
	 * @return void
	 */
	public function test_post_is_forbidden_for_subscriber(): void {
		$this->actingAs( 'subscriber' );

		$response = $this->dispatch( 'POST', array( 'abilities' => array( 'og-content/get-post' => true ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotContains( 'og-content/get-post', ExposurePolicy::stored() );
	}

	/**
	 * Dispatches a request at the exposure route with the given params.
	 *
	 * @param string              $method The HTTP method.
	 * @param array<string,mixed> $params The request params.
	 * @return \WP_REST_Response The dispatched response.
	 */
	private function dispatch( string $method, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/abilities-catalog/v1/exposure' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_ensure_response( $this->server->dispatch( $request ) );
	}

	/**
	 * Finds an ability's `enabled` flag in a state payload.
	 *
	 * @param array<string,mixed> $data The state payload.
	 * @param string              $name The ability name.
	 * @return bool The ability's enabled flag (false when absent).
	 */
	private function abilityEnabled( array $data, string $name ): bool {
		foreach ( $data['domains'] as $domain ) {
			foreach ( $domain['abilities'] as $ability ) {
				if ( $ability['name'] === $name ) {
					return (bool) $ability['enabled'];
				}
			}
		}

		return false;
	}
}
