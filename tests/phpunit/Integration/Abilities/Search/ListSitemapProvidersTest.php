<?php
/**
 * Integration tests for the `og-search/list-sitemap-providers` ability.
 *
 * Covers registration, the happy-path provider listing (the three core
 * providers and their object subtypes), the closed output shape, and the
 * capability guard for logged-out and under-privileged callers.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Search;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-search/list-sitemap-providers.
 */
final class ListSitemapProvidersTest extends TestCase {

	/**
	 * Boots the sitemaps server so the default providers are registered.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_sitemaps_get_server();
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'og-search/list-sitemap-providers' ) );
	}

	public function test_lists_core_providers_with_subtypes(): void {
		$this->actingAs( 'editor' );

		$result = wp_get_ability( 'og-search/list-sitemap-providers' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'providers', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['providers'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 3, $result['total'] );
		$this->assertSame( count( $result['providers'] ), $result['total'] );

		$by_name = array();
		foreach ( $result['providers'] as $provider ) {
			$this->assertSame( array( 'name', 'subtypes' ), array_keys( $provider ) );
			$this->assertIsString( $provider['name'] );
			$this->assertIsArray( $provider['subtypes'] );
			$by_name[ $provider['name'] ] = $provider['subtypes'];
		}

		// Core registers posts, taxonomies, and users by default.
		$this->assertArrayHasKey( 'posts', $by_name );
		$this->assertArrayHasKey( 'taxonomies', $by_name );
		$this->assertArrayHasKey( 'users', $by_name );
		// The posts provider exposes the `post` post type as a subtype.
		$this->assertContains( 'post', $by_name['posts'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-search/list-sitemap-providers' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-search/list-sitemap-providers' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
