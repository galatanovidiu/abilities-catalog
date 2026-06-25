<?php
/**
 * Integration tests for the og-content/get-post-type ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content\GetPostType;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-content/get-post-type end-to-end: a registered post-type slug in,
 * a flat shaped field set out. The wrapped GET /wp/v2/types/{type} route allows
 * public view reads, so the ability defers permission to it.
 */
final class GetPostTypeTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/get-post-type' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/get-post-type', $ability->get_name() );
	}

	public function test_returns_shaped_fields_for_post_type(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array( 'type' => 'post' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'post', $result['slug'] );
		$this->assertIsString( $result['name'] );
		$this->assertNotSame( '', $result['name'] );
		$this->assertIsString( $result['description'] );
		$this->assertFalse( $result['hierarchical'] );
		$this->assertTrue( $result['viewable'] );
		$this->assertSame( 'posts', $result['rest_base'] );
		$this->assertIsArray( $result['taxonomies'] );
		$this->assertContains( 'category', $result['taxonomies'] );
		$this->assertIsArray( $result['supports'] );
		$this->assertContains( 'title', $result['supports'] );
	}

	public function test_hierarchical_type_is_flagged(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array( 'type' => 'page' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'page', $result['slug'] );
		$this->assertTrue( $result['hierarchical'] );
	}

	public function test_output_key_set_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array( 'type' => 'post' ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'slug', 'name', 'description', 'hierarchical', 'viewable', 'rest_base', 'taxonomies', 'supports', 'icon' ),
			array_keys( $result )
		);
	}

	public function test_unknown_type_returns_specific_not_found_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array( 'type' => 'does-not-exist' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Core's specific 404, never the generic permission collapse.
		$this->assertSame( 'rest_type_invalid', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	public function test_non_rest_post_type_is_not_readable(): void {
		$this->actingAs( 'administrator' );

		register_post_type(
			'ac_hidden_type',
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array( 'type' => 'ac_hidden_type' ) );

		unregister_post_type( 'ac_hidden_type' );

		$this->assertInstanceOf( WP_Error::class, $result );
		// The route refuses a non-REST type rather than leaking its details.
		$this->assertSame( 'rest_cannot_read_type', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_missing_type_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_public_view_read_is_allowed_logged_out(): void {
		wp_set_current_user( 0 );

		// The wrapped types/{type} view route is public, so the ability defers to
		// it and does not collapse a logged-out reader into a permission error.
		$result = wp_get_ability( 'og-content/get-post-type' )->execute( array( 'type' => 'post' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'post', $result['slug'] );
	}

	public function test_direct_instantiation_executes(): void {
		$this->actingAs( 'administrator' );

		// Exercise the class wiring directly, not only the registry lookup.
		$result = ( new GetPostType() )->execute( array( 'type' => 'post' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'post', $result['slug'] );
	}
}
