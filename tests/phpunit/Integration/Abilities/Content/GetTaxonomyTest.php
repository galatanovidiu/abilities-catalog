<?php
/**
 * Integration tests for the content/get-taxonomy ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content\GetTaxonomy;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises content/get-taxonomy end-to-end: a registered taxonomy slug in,
 * a flat shaped field set out. The wrapped GET /wp/v2/taxonomies/{taxonomy}
 * route allows public view reads, so the ability defers permission to it.
 */
final class GetTaxonomyTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'content/get-taxonomy' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'content/get-taxonomy', $ability->get_name() );
	}

	public function test_returns_shaped_fields_for_taxonomy(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-taxonomy' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['slug'] );
		$this->assertIsString( $result['name'] );
		$this->assertNotSame( '', $result['name'] );
		$this->assertIsString( $result['description'] );
		$this->assertTrue( $result['hierarchical'] );
		$this->assertIsArray( $result['types'] );
		$this->assertContains( 'post', $result['types'] );
		$this->assertSame( 'categories', $result['rest_base'] );
		$this->assertIsString( $result['rest_namespace'] );
		$this->assertIsBool( $result['public'] );
		$this->assertIsBool( $result['show_cloud'] );
	}

	public function test_flat_taxonomy_is_flagged_non_hierarchical(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-taxonomy' )->execute( array( 'taxonomy' => 'post_tag' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'post_tag', $result['slug'] );
		$this->assertFalse( $result['hierarchical'] );
	}

	public function test_output_key_set_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-taxonomy' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'slug', 'name', 'description', 'hierarchical', 'types', 'rest_base', 'rest_namespace', 'public', 'show_cloud' ),
			array_keys( $result )
		);
	}

	public function test_unknown_taxonomy_returns_specific_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-taxonomy' )->execute( array( 'taxonomy' => 'nope_xyz' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Core's specific 404, never the generic permission collapse.
		$this->assertSame( 'rest_taxonomy_invalid', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_missing_taxonomy_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-taxonomy' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_public_view_read_is_allowed_logged_out(): void {
		wp_set_current_user( 0 );

		// The wrapped taxonomies/{taxonomy} view route is public, so the ability
		// defers to it and does not collapse a logged-out reader into a
		// permission error.
		$result = wp_get_ability( 'content/get-taxonomy' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['slug'] );
	}

	public function test_direct_instantiation_executes(): void {
		$this->actingAs( 'administrator' );

		// Exercise the class wiring directly, not only the registry lookup.
		$result = ( new GetTaxonomy() )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['slug'] );
	}
}
