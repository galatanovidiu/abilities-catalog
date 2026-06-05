<?php
/**
 * Integration tests for content/create-page output fidelity.
 *
 * Covers the signed menu_order pass-through and the additive output fields
 * (slug, date, featured_media) the ability returns so a caller can detect how
 * core resolved the request.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises content/create-page output.
 */
final class CreatePageTest extends TestCase {

	public function test_negative_menu_order_reaches_post_unchanged(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-page' )->execute(
			array(
				'title'      => 'Ordered page',
				'menu_order' => -5,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( -5, get_post( $result['id'] )->menu_order );
	}

	public function test_output_returns_slug_date_and_featured_media(): void {
		$this->actingAs( 'administrator' );

		// Publish so core assigns a public slug (drafts expose an empty slug).
		$result = wp_get_ability( 'content/create-page' )->execute(
			array(
				'title'  => 'Has slug',
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertNotEmpty( $result['slug'] );
		$this->assertArrayHasKey( 'date', $result );
		$this->assertNotEmpty( $result['date'] );
		$this->assertArrayHasKey( 'featured_media', $result );
		$this->assertSame( 0, $result['featured_media'] );
	}

	public function test_invalid_featured_media_yields_zero_in_output(): void {
		$this->actingAs( 'administrator' );

		// A non-existent attachment ID: core silently ignores it on create.
		$result = wp_get_ability( 'content/create-page' )->execute(
			array(
				'title'          => 'No thumbnail',
				'featured_media' => 999999,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['featured_media'] );
	}
}
