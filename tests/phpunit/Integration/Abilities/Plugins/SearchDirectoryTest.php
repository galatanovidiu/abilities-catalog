<?php
/**
 * Integration tests for the plugins/search-directory ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the shaped output, the pagination fields, the capability gate, and the
 * error-preservation contract for the plugins/search-directory ability.
 *
 * The outbound WordPress.org call is short-circuited via the core `plugins_api`
 * filter, so no network is needed: returning a non-false value from that filter
 * skips the HTTP request (wp-admin/includes/plugin-install.php:146).
 */
final class SearchDirectoryTest extends TestCase {

	/**
	 * Holds the stub the `plugins_api` filter should return, if any.
	 *
	 * @var object|\WP_Error|null
	 */
	private $api_stub = null;

	public function tear_down(): void {
		remove_filter( 'plugins_api', array( $this, 'shortCircuitPluginsApi' ), 10 );
		$this->api_stub = null;
		parent::tear_down();
	}

	/**
	 * Short-circuits `plugins_api()` with the configured stub.
	 *
	 * @return object|\WP_Error|false The stub, or false to fall through to HTTP.
	 */
	public function shortCircuitPluginsApi() {
		return null !== $this->api_stub ? $this->api_stub : false;
	}

	/**
	 * Registers the short-circuit filter with a given stub response.
	 *
	 * @param object|\WP_Error $stub The response to return from `plugins_api()`.
	 */
	private function stubPluginsApi( $stub ): void {
		$this->api_stub = $stub;
		add_filter( 'plugins_api', array( $this, 'shortCircuitPluginsApi' ), 10, 0 );
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/search-directory' ) );
	}

	public function test_returns_shaped_items_and_pagination_for_admin(): void {
		$this->actingAs( 'administrator' );
		$this->stubPluginsApi(
			(object) array(
				'plugins' => array(
					(object) array(
						'slug'              => 'akismet',
						'name'              => 'Akismet <b>Anti-Spam</b>',
						'version'           => '5.3',
						'rating'            => 92.4,
						'num_ratings'       => 1000,
						'active_installs'   => 5000000,
						'short_description' => 'Stops <em>spam</em>.',
						'author'            => '<a href="#">Automattic</a>',
					),
				),
				'info'    => array( 'results' => 50 ),
			)
		);

		$result = wp_get_ability( 'plugins/search-directory' )->execute( array( 'search' => 'spam' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );

		$item = $result['items'][0];
		$this->assertSame( 'akismet', $item['slug'] );
		$this->assertSame( 'Akismet Anti-Spam', $item['name'], 'name must be plain text.' );
		$this->assertSame( 92, $item['rating'], 'rating rounds to an int percentage.' );
		$this->assertSame( 'Stops spam.', $item['short_description'] );
		$this->assertSame( 'Automattic', $item['author'], 'author must be plain text.' );

		$this->assertSame( 50, $result['total_results'] );
		$this->assertTrue( $result['has_more'], 'page 1 of 10 with 50 total results has more.' );
	}

	public function test_has_more_is_false_when_page_covers_all_results(): void {
		$this->actingAs( 'administrator' );
		$this->stubPluginsApi(
			(object) array(
				'plugins' => array(),
				'info'    => array( 'results' => 5 ),
			)
		);

		$result = wp_get_ability( 'plugins/search-directory' )->execute(
			array(
				'search'   => 'spam',
				'page'     => 1,
				'per_page' => 10,
			)
		);

		$this->assertSame( 5, $result['total_results'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_wp_error_from_api_is_preserved(): void {
		$this->actingAs( 'administrator' );
		$this->stubPluginsApi( new WP_Error( 'plugins_api_failed', 'boom' ) );

		$result = wp_get_ability( 'plugins/search-directory' )->execute( array( 'search' => 'spam' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plugins_api_failed', $result->get_error_code(), 'stable core code is preserved.' );
		$this->assertSame( array( 'status' => 502 ), $result->get_error_data(), '502 added when core error has no status.' );
	}

	public function test_wp_error_with_existing_status_is_not_overwritten(): void {
		$this->actingAs( 'administrator' );
		$this->stubPluginsApi( new WP_Error( 'plugins_api_failed', 'boom', array( 'status' => 418 ) ) );

		$result = wp_get_ability( 'plugins/search-directory' )->execute( array( 'search' => 'spam' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'status' => 418 ), $result->get_error_data() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'plugins/search-directory' )->execute( array( 'search' => 'spam' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_output_schema_declares_pagination_fields(): void {
		$schema = wp_get_ability( 'plugins/search-directory' )->get_output_schema();

		$this->assertArrayHasKey( 'total_results', $schema['properties'] );
		$this->assertArrayHasKey( 'has_more', $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}
}
