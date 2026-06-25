<?php
/**
 * Integration tests for og-search/search-content output fidelity and guards.
 *
 * Covers the happy-path post-search shape, the term-result `type` normalization
 * (core sets the row type to the taxonomy slug, the ability must report the
 * requested search type), the omission of `subtype` when core does not set it,
 * the additive pagination totals, the capability guard, and the preserved core
 * error contracts for invalid type and invalid page.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Search;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Search\SearchContent;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-search/search-content.
 */
final class SearchContentTest extends TestCase {

	public function test_post_search_returns_shaped_items_with_post_type(): void {
		$this->actingAs( 'administrator' );

		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Findable widget article',
			)
		);

		$result = wp_get_ability( 'og-search/search-content' )->execute(
			array(
				'search' => 'Findable widget',
				'type'   => 'post',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertNotEmpty( $result['items'] );

		$item = $result['items'][0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'title', $item );
		$this->assertArrayHasKey( 'url', $item );
		// Post results carry the search type and the post handler's subtype.
		$this->assertSame( 'post', $item['type'] );
		$this->assertArrayHasKey( 'subtype', $item );
		$this->assertSame( 'post', $item['subtype'] );
	}

	public function test_term_search_normalizes_type_to_search_type(): void {
		$this->actingAs( 'administrator' );

		self::factory()->category->create( array( 'name' => 'Findable taxonomy term' ) );

		$result = wp_get_ability( 'og-search/search-content' )->execute(
			array(
				'search' => 'Findable taxonomy',
				'type'   => 'term',
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		$item = $result['items'][0];
		// Core sets the row type to the taxonomy slug (e.g. `category`); the
		// ability must report the requested search type instead.
		$this->assertSame( 'term', $item['type'] );
		// Core's term handler never sets `subtype`, so the ability omits it
		// rather than inventing an empty string.
		$this->assertArrayNotHasKey( 'subtype', $item );
	}

	public function test_output_includes_pagination_totals(): void {
		$this->actingAs( 'administrator' );

		self::factory()->post->create_many(
			3,
			array(
				'post_status' => 'publish',
				'post_title'  => 'Paginated marker post',
			)
		);

		$result = wp_get_ability( 'og-search/search-content' )->execute(
			array(
				'search'   => 'Paginated marker',
				'per_page' => 2,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		// Three matches at two per page report the real totals from core's
		// X-WP-Total / X-WP-TotalPages headers, not just the page body length.
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( 2, $result['total_pages'] );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-search/search-content' )->execute(
			array( 'search' => 'anything' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_invalid_type_preserves_core_error(): void {
		$this->actingAs( 'administrator' );

		// Call execute() directly to bypass the input-schema enum and reach
		// core's invalid-type guard, which the ability must surface unchanged.
		$result = ( new SearchContent() )->execute(
			array(
				'search' => 'anything',
				'type'   => 'not-a-real-type',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// The wrapped route validates `type` against its enum, so core returns
		// rest_invalid_param (400). The ability surfaces it unchanged.
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
	}

	public function test_out_of_range_page_returns_empty_items(): void {
		$this->actingAs( 'administrator' );

		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Single page-bound marker post',
			)
		);

		// A page past the result set yields an empty list, not a fabricated row.
		$result = wp_get_ability( 'og-search/search-content' )->execute(
			array(
				'search'   => 'page-bound marker',
				'page'     => 999,
				'per_page' => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['items'] );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
	}
}
