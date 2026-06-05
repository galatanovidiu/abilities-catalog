<?php
/**
 * Integration tests for the themes/search-directory ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the shaped output, the pagination fields, the capability gate, and the
 * error-preservation contract for the themes/search-directory ability.
 *
 * The outbound WordPress.org call is short-circuited via the core `themes_api`
 * filter, so no network is needed: returning a non-false value from that filter
 * skips the HTTP request (wp-admin/includes/theme.php:538).
 */
final class SearchDirectoryTest extends TestCase {

	/**
	 * Holds the stub the `themes_api` filter should return, if any.
	 *
	 * @var object|\WP_Error|null
	 */
	private $api_stub = null;

	public function tear_down(): void {
		remove_filter( 'themes_api', array( $this, 'shortCircuitThemesApi' ), 10 );
		$this->api_stub = null;
		parent::tear_down();
	}

	/**
	 * Short-circuits `themes_api()` with the configured stub.
	 *
	 * @return object|\WP_Error|false The stub, or false to fall through to HTTP.
	 */
	public function shortCircuitThemesApi() {
		return null !== $this->api_stub ? $this->api_stub : false;
	}

	/**
	 * Registers the short-circuit filter with a given stub response.
	 *
	 * @param object|\WP_Error $stub The response to return from `themes_api()`.
	 */
	private function stubThemesApi( $stub ): void {
		$this->api_stub = $stub;
		add_filter( 'themes_api', array( $this, 'shortCircuitThemesApi' ), 10, 0 );
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'themes/search-directory' ) );
	}

	public function test_returns_shaped_items_and_pagination_for_admin(): void {
		$this->actingAs( 'administrator' );
		$this->stubThemesApi(
			(object) array(
				'themes' => array(
					(object) array(
						'slug'        => 'twentytwentyfour',
						'name'        => 'Twenty <b>Twenty-Four</b>',
						'version'     => '1.2',
						'rating'      => 92.4,
						'num_ratings' => 1000,
						'preview_url' => 'https://example.org/preview',
						'author'      => array( 'display_name' => '<a href="#">WordPress.org</a>' ),
					),
				),
				'info'   => array( 'results' => 50 ),
			)
		);

		$result = wp_get_ability( 'themes/search-directory' )->execute( array( 'search' => 'twenty' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items'] );

		$item = $result['items'][0];
		$this->assertSame( 'twentytwentyfour', $item['slug'] );
		$this->assertSame( 'Twenty Twenty-Four', $item['name'], 'name must be plain text.' );
		$this->assertSame( 92, $item['rating'], 'rating rounds to an int percentage.' );
		$this->assertSame( 1000, $item['num_ratings'] );
		$this->assertSame( 'https://example.org/preview', $item['preview_url'] );
		$this->assertSame( 'WordPress.org', $item['author'], 'array-shaped author resolves to plain text.' );

		$this->assertSame( 50, $result['total_results'] );
		$this->assertTrue( $result['has_more'], 'page 1 of 10 with 50 total results has more.' );
	}

	public function test_rating_fields_are_requested(): void {
		$this->actingAs( 'administrator' );

		$captured = null;
		add_filter(
			'themes_api_args',
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args;
			},
			10,
			1
		);
		$this->stubThemesApi(
			(object) array(
				'themes' => array(),
				'info'   => array( 'results' => 0 ),
			)
		);

		wp_get_ability( 'themes/search-directory' )->execute( array( 'search' => 'twenty' ) );

		$this->assertIsObject( $captured );
		$fields = (array) $captured->fields;
		$this->assertTrue( $fields['rating'], 'rating must be requested or it returns 0.' );
		$this->assertTrue( $fields['num_ratings'], 'num_ratings must be requested or it returns 0.' );
	}

	public function test_has_more_is_false_when_page_covers_all_results(): void {
		$this->actingAs( 'administrator' );
		$this->stubThemesApi(
			(object) array(
				'themes' => array(),
				'info'   => array( 'results' => 5 ),
			)
		);

		$result = wp_get_ability( 'themes/search-directory' )->execute(
			array(
				'search'   => 'twenty',
				'page'     => 1,
				'per_page' => 10,
			)
		);

		$this->assertSame( 5, $result['total_results'] );
		$this->assertFalse( $result['has_more'] );
	}

	public function test_wp_error_from_api_is_preserved(): void {
		$this->actingAs( 'administrator' );
		$this->stubThemesApi( new WP_Error( 'themes_api_failed', 'boom' ) );

		$result = wp_get_ability( 'themes/search-directory' )->execute( array( 'search' => 'twenty' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'themes_api_failed', $result->get_error_code(), 'stable core code is preserved.' );
		$this->assertSame( array( 'status' => 502 ), $result->get_error_data(), '502 added when core error has no status.' );
	}

	public function test_wp_error_with_existing_status_is_not_overwritten(): void {
		$this->actingAs( 'administrator' );
		$this->stubThemesApi( new WP_Error( 'themes_api_failed', 'boom', array( 'status' => 418 ) ) );

		$result = wp_get_ability( 'themes/search-directory' )->execute( array( 'search' => 'twenty' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'status' => 418 ), $result->get_error_data() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'themes/search-directory' )->execute( array( 'search' => 'twenty' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_output_schema_declares_pagination_fields(): void {
		$schema = wp_get_ability( 'themes/search-directory' )->get_output_schema();

		$this->assertArrayHasKey( 'total_results', $schema['properties'] );
		$this->assertArrayHasKey( 'has_more', $schema['properties'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}
}
