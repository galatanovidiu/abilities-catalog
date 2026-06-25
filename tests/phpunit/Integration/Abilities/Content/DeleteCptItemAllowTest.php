<?php
/**
 * Integration tests for the delete-cpt-item post-like-deletable allow-test.
 *
 * Proves delete-cpt-item only deletes items of post-like deletable types and
 * rejects non-post-like types (template, global styles, font face, navigation)
 * up-front with a stable `unsupported_post_type` error — so it cannot permanently
 * delete a navigation menu or attachment its description lists as unsupported.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the post-like-deletable allow-test for og-content/delete-cpt-item.
 */
final class DeleteCptItemAllowTest extends TestCase {

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

	public function test_supported_custom_post_type_still_deletes(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Doomed',
			)
		);

		$result = wp_get_ability( 'og-content/delete-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'id'        => $post_id,
			)
		);

		$this->assertIsArray( $result, 'A custom post type on the base posts controller must still delete.' );
		$this->assertTrue( $result['deleted'] );
		$this->assertNull( get_post( $post_id ), 'The item must be permanently deleted.' );
	}

	public function test_navigation_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		// A real navigation post that the ability must refuse to delete.
		$nav_id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'publish',
				'post_title'  => 'Primary',
			)
		);

		$result = wp_get_ability( 'og-content/delete-cpt-item' )->execute(
			array(
				'post_type' => 'wp_navigation',
				'id'        => $nav_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'navigation is not a plain deletable post and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertNotNull( get_post( $nav_id ), 'The navigation menu must NOT have been deleted.' );
	}

	public function test_attachment_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create();

		$result = wp_get_ability( 'og-content/delete-cpt-item' )->execute(
			array(
				'post_type' => 'attachment',
				'id'        => $attachment_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'attachments use a different delete contract and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertNotNull( get_post( $attachment_id ), 'The attachment must NOT have been deleted.' );
	}

	public function test_template_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/delete-cpt-item' )->execute(
			array(
				'post_type' => 'wp_template',
				'id'        => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'templates use a different delete contract and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_global_styles_rejected_up_front(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/delete-cpt-item' )->execute(
			array(
				'post_type' => 'wp_global_styles',
				'id'        => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'global-styles has no collection-create route and must be rejected.' );
		$this->assertSame( 'unsupported_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_unknown_type_returns_invalid_post_type(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/delete-cpt-item' )->execute(
			array(
				'post_type' => 'does_not_exist',
				'id'        => 1,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An unknown type must error.' );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}
}
