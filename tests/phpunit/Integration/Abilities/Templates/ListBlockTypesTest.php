<?php
/**
 * Integration tests for og-templates/list-block-types output and contract.
 *
 * Covers the happy path (returns an `items` array whose rows carry the four
 * guaranteed keys and includes `core/paragraph`), the output-shape guarantee
 * (each row exposes only the declared flat keys), and the wrong-capability
 * denial (a subscriber lacks edit_posts).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-templates/list-block-types.
 */
final class ListBlockTypesTest extends TestCase {

	public function test_returns_items_with_guaranteed_keys_including_core_paragraph(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-block-types' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		$names = array();
		foreach ( $result['items'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'title', $row );
			$this->assertArrayHasKey( 'category', $row );
			$this->assertArrayHasKey( 'is_dynamic', $row );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['title'] );
			$this->assertIsString( $row['category'] );
			$this->assertIsBool( $row['is_dynamic'] );
			$names[] = $row['name'];
		}

		$this->assertContains( 'core/paragraph', $names );
	}

	public function test_rows_expose_only_declared_flat_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-templates/list-block-types' )->execute();

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		// The output schema locks each row to exactly these flat keys with
		// additionalProperties:false; the shaped result must not leak extras.
		$expected = array( 'name', 'title', 'category', 'is_dynamic' );
		foreach ( $result['items'] as $row ) {
			$this->assertSame( $expected, array_keys( $row ) );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		// edit_posts is the catalog guard; a subscriber lacks it.
		$result = wp_get_ability( 'og-templates/list-block-types' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
