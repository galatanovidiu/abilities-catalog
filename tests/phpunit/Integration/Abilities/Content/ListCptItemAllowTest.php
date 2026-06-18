<?php
/**
 * Integration tests for the list-cpt-items post-like-listable allow-test.
 *
 * Proves list-cpt-items only lists items of post-like listable types and rejects
 * non-post-like types (template, global styles) up-front with a stable
 * `unsupported_post_type` error instead of a misleading `total:0` or a
 * differently-shaped collection.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the post-like-listable allow-test for content/list-cpt-items.
 */
final class ListCptItemAllowTest extends TestCase {

	private const POST_TYPE = 'catalog_widget';

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			self::POST_TYPE,
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'rest_base'       => 'catalog-gadgets',
				'rest_namespace'  => 'catalog/v1',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'author', 'excerpt' ),
			)
		);

		// Reset the REST server so the custom-namespace route is registered.
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- core global; rebuild routes for the new CPT.
		rest_get_server();
	}

	public function tear_down(): void {
		unregister_post_type( self::POST_TYPE );
		parent::tear_down();
	}

	public function test_builtin_post_type_still_lists(): void {
		$this->actingAs( 'administrator' );

		self::factory()->post->create_many(
			3,
			array( 'post_status' => 'publish' )
		);

		$result = wp_get_ability( 'content/list-cpt-items' )->execute(
			array( 'post_type' => 'post' )
		);

		$this->assertIsArray( $result, 'A post-like core type must still list.' );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 3, $result['items'] );
	}

	public function test_supported_custom_post_type_still_lists(): void {
		$this->actingAs( 'administrator' );

		self::factory()->post->create_many(
			2,
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'content/list-cpt-items' )->execute(
			array( 'post_type' => self::POST_TYPE )
		);

		$this->assertIsArray( $result, 'A custom post type on the base posts controller must still list.' );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_template_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-cpt-items' )->execute(
			array( 'post_type' => 'wp_template' )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'templates use a different list contract and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_global_styles_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-cpt-items' )->execute(
			array( 'post_type' => 'wp_global_styles' )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'global-styles has no post-like collection read and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_unknown_type_returns_invalid_post_type(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-cpt-items' )->execute(
			array( 'post_type' => 'does_not_exist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An unknown type must error.' );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}
}
