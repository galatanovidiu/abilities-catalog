<?php
/**
 * Integration tests for the update-cpt-item post-like-updatable allow-test.
 *
 * Proves update-cpt-item only updates items of post-like updatable types and
 * rejects non-post-like types (template, global styles, font face, navigation)
 * up-front with a stable `unsupported_post_type` error instead of a late no-route
 * or core-validation failure.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the post-like-updatable allow-test for content/update-cpt-item.
 */
final class UpdateCptItemAllowTest extends TestCase {

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

	public function test_supported_custom_post_type_still_updates(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Before',
			)
		);

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'id'        => $post_id,
				'title'     => 'After',
			)
		);

		$this->assertIsArray( $result, 'A custom post type on the base posts controller must still update.' );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'After', $result['title'] );
	}

	public function test_template_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'wp_template',
				'id'        => 1,
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'templates use a different update contract and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_global_styles_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'wp_global_styles',
				'id'        => 1,
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'global-styles has no collection-create route and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_font_face_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'wp_font_face',
				'id'        => 1,
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'font faces use a different update contract and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_navigation_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'wp_navigation',
				'id'        => 1,
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'navigation is not a plain updatable post and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_unknown_type_returns_invalid_post_type(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-cpt-item' )->execute(
			array(
				'post_type' => 'does_not_exist',
				'id'        => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An unknown type must error.' );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}
}
