<?php
/**
 * Integration tests for the create-cpt-item post-like-creatable allow-test.
 *
 * Proves create-cpt-item only creates items of post-like creatable types and
 * rejects non-post-like types (global styles, attachment, navigation) up-front
 * with a stable `unsupported_post_type` error instead of a late no-route or
 * core-validation failure.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the post-like-creatable allow-test for content/create-cpt-item.
 */
final class CreateCptItemAllowTest extends TestCase {

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

	public function test_supported_builtin_post_type_still_creates(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Hello',
			)
		);

		$this->assertIsArray( $result, 'A post-like core type must still create.' );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'post', $result['type'] );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_supported_custom_post_type_still_creates(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'title'     => 'New widget',
				'status'    => 'publish',
			)
		);

		$this->assertIsArray( $result, 'A custom post type on the base posts controller must still create.' );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( self::POST_TYPE, $result['type'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_global_styles_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => 'wp_global_styles',
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'global-styles has no collection-create route and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_attachment_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => 'attachment',
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'attachment uses an upload create contract and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
	}

	public function test_navigation_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => 'wp_navigation',
				'title'     => 'Nope',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'navigation is not a plain draftable post and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
	}

	public function test_unknown_type_returns_invalid_post_type(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array( 'post_type' => 'does_not_exist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An unknown type must error.' );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}
}
