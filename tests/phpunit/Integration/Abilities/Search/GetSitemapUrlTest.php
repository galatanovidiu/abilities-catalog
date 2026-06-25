<?php
/**
 * Integration tests for og-search/get-sitemap-url.
 *
 * Covers the happy-path output shape (the index URL and the enabled flag),
 * the enabled flag tracking the blog_public Reading setting, the exact output
 * key set, and the capability guards (editor allowed, subscriber and logged-out
 * denied).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Search;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-search/get-sitemap-url.
 */
final class GetSitemapUrlTest extends TestCase {

	/**
	 * The blog_public option value before the test, restored in tearDown.
	 *
	 * @var mixed
	 */
	private $blog_public_before;

	public function set_up(): void {
		parent::set_up();
		$this->blog_public_before = get_option( 'blog_public' );
	}

	public function tear_down(): void {
		update_option( 'blog_public', $this->blog_public_before );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-search/get-sitemap-url' ) );
	}

	public function test_returns_index_url_and_enabled_flag(): void {
		$this->actingAs( 'administrator' );
		update_option( 'blog_public', 1 );

		$result = wp_get_ability( 'og-search/get-sitemap-url' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'sitemap_url', 'enabled' ), array_keys( $result ) );

		$this->assertIsString( $result['sitemap_url'] );
		$this->assertNotEmpty( $result['sitemap_url'] );
		$this->assertTrue(
			str_ends_with( $result['sitemap_url'], 'wp-sitemap.xml' )
				|| str_contains( $result['sitemap_url'], 'sitemap=index' ),
			'The sitemap URL must be the index URL.'
		);

		$this->assertIsBool( $result['enabled'] );
		$this->assertTrue( $result['enabled'] );
	}

	public function test_enabled_reflects_blog_public_off(): void {
		$this->actingAs( 'administrator' );
		update_option( 'blog_public', 0 );

		$result = wp_get_ability( 'og-search/get-sitemap-url' )->execute();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['enabled'] );
		// The URL still resolves even when crawling is discouraged.
		$this->assertNotEmpty( $result['sitemap_url'] );
	}

	public function test_editor_is_allowed(): void {
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'og-search/get-sitemap-url' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'sitemap_url', $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-search/get-sitemap-url' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-search/get-sitemap-url' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
