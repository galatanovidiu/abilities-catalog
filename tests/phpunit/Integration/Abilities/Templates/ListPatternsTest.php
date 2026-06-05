<?php
/**
 * Integration tests for templates/list-patterns output and contract.
 *
 * Covers the happy path (returns an `items` array whose rows carry the two
 * guaranteed keys), the output-shape guarantee (each row exposes only the
 * declared closed fields), and the wrong-capability denial (a subscriber lacks
 * edit_posts).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises templates/list-patterns.
 */
final class ListPatternsTest extends TestCase {

	public function test_returns_items_with_guaranteed_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/list-patterns' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'title', $row );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['title'] );
		}
	}

	public function test_rows_expose_only_declared_closed_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'templates/list-patterns' )->execute();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// The output schema locks each row to this closed set with
		// additionalProperties:false; the shaped result must not leak extras.
		$allowed = array(
			'name',
			'title',
			'description',
			'content',
			'viewport_width',
			'inserter',
			'categories',
			'keywords',
			'block_types',
			'post_types',
			'template_types',
			'source',
		);
		foreach ( $result['items'] as $row ) {
			foreach ( array_keys( $row ) as $key ) {
				$this->assertContains( $key, $allowed, "Unexpected row field: {$key}" );
			}
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// edit_posts is the catalog guard; a subscriber lacks it.
		$result = wp_get_ability( 'templates/list-patterns' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
