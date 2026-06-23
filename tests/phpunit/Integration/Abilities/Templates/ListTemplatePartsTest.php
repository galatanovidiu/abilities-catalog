<?php
/**
 * Integration tests for templates/list-template-parts output and contract.
 *
 * Covers registration, the happy path (returns an `items` array whose rows
 * carry the guaranteed `id` and the always-present `area`), the area filter
 * (every returned row's area matches the requested one), and the denial cases
 * (logged-out and subscriber both lack edit_theme_options). Seeds a header part
 * with create-template-part so the collection is non-empty and the area filter
 * has a row to match; uses only the valid "header" area so no core
 * area-fallback warning fires. Cleans up the seeded part in tearDown.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/list-template-parts.
 */
final class ListTemplatePartsTest extends TestCase {

	/**
	 * Underlying post ID of a seeded part, for cleanup.
	 *
	 * @var int
	 */
	private int $seeded_wp_id = 0;

	public function tearDown(): void {
		if ( $this->seeded_wp_id > 0 ) {
			wp_delete_post( $this->seeded_wp_id, true );
			$this->seeded_wp_id = 0;
		}

		parent::tearDown();
	}

	/**
	 * Seeds a user-created header part so the collection is non-empty.
	 *
	 * @param string $slug The part slug.
	 * @return void
	 */
	private function seedHeaderPart( string $slug ): void {
		$created = wp_get_ability( 'templates/create-template-part' )->execute(
			array(
				'slug'    => $slug,
				'area'    => 'header',
				'title'   => 'List Parts ' . $slug,
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertIsArray( $created );

		$part = get_block_template( $created['id'], 'wp_template_part' );
		$this->assertInstanceOf( \WP_Block_Template::class, $part );
		$this->seeded_wp_id = (int) $part->wp_id;
	}

	public function test_ability_is_registered(): void {
		$this->assertTrue( wp_has_ability( 'templates/list-template-parts' ) );
	}

	public function test_returns_items_with_id_and_area(): void {
		$this->actingAs( 'administrator' );

		$this->seedHeaderPart( 'abilities-catalog-list-part-a' );

		$result = wp_get_ability( 'templates/list-template-parts' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		// The active block theme ships parts and we seeded one, so it is non-empty.
		$this->assertNotEmpty( $result['items'] );

		$allowed = array(
			'id',
			'slug',
			'theme',
			'area',
			'source',
			'title',
			'status',
			'original_source',
		);
		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'id', $row );
			$this->assertIsString( $row['id'] );
			// area is always surfaced for parts.
			$this->assertArrayHasKey( 'area', $row );
			$this->assertIsString( $row['area'] );

			// The schema locks each row to a closed set; the projection must not leak.
			foreach ( array_keys( $row ) as $key ) {
				$this->assertContains( $key, $allowed, "Unexpected row field: {$key}" );
			}
		}
	}

	public function test_area_filter_returns_only_that_area(): void {
		$this->actingAs( 'administrator' );

		$this->seedHeaderPart( 'abilities-catalog-list-part-b' );

		$result = wp_get_ability( 'templates/list-template-parts' )->execute(
			array( 'area' => 'header' )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );

		// Every returned row must be a header part; tolerate an empty list if the
		// theme happens to have none (the seeded part keeps it non-empty here).
		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'header', $row['area'] );
		}
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'templates/list-template-parts' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// edit_theme_options is the catalog guard; a subscriber lacks it.
		$result = wp_get_ability( 'templates/list-template-parts' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
