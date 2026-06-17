<?php
/**
 * Integration tests for the terms/list-taxonomies ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises the taxonomy discovery read ability: registration, the flat
 * list shape with the five declared keys, and that the built-in
 * `category`/`post_tag` taxonomies appear in the result.
 */
final class ListTaxonomiesTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'terms/list-taxonomies' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'terms/list-taxonomies', $ability->get_name() );
	}

	/**
	 * Happy path: the result is a list of objects, each carrying exactly the
	 * five declared keys with the declared types.
	 */
	public function test_returns_flat_taxonomy_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/list-taxonomies' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertNotEmpty( $result['items'] );

		foreach ( $result['items'] as $item ) {
			$this->assertIsArray( $item );
			$this->assertSame(
				array( 'name', 'slug', 'types', 'hierarchical', 'rest_base' ),
				array_keys( $item )
			);
			$this->assertIsString( $item['name'] );
			$this->assertIsString( $item['slug'] );
			$this->assertIsArray( $item['types'] );
			$this->assertIsBool( $item['hierarchical'] );
			$this->assertIsString( $item['rest_base'] );
		}
	}

	/**
	 * The built-in `category` and `post_tag` taxonomies appear, with their
	 * known hierarchical flags.
	 */
	public function test_built_in_taxonomies_appear(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/list-taxonomies' )->execute( array() );

		$by_slug = array();
		foreach ( $result['items'] as $item ) {
			$by_slug[ $item['slug'] ] = $item;
		}

		$this->assertArrayHasKey( 'category', $by_slug );
		$this->assertArrayHasKey( 'post_tag', $by_slug );
		$this->assertTrue( $by_slug['category']['hierarchical'] );
		$this->assertFalse( $by_slug['post_tag']['hierarchical'] );
	}
}
