<?php
/**
 * Integration tests for create/update/delete/list cpt-item route resolution.
 *
 * Proves each write/list ability resolves a post type's REST route via
 * rest_get_route_for_post_type_items(), so a CPT registered with a custom
 * rest_namespace is reached at its real route instead of a hardcoded /wp/v2 path
 * (which would 404 with rest_no_route).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises route resolution for the generic CPT write/list abilities.
 */
final class CptItemRouteTest extends TestCase {

	private const POST_TYPE = 'catalog_widget';

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'catalog_widget',
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

	public function test_create_resolves_custom_rest_namespace(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/create-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'title'     => 'New widget',
				'status'    => 'publish',
			)
		);

		$this->assertIsArray( $result, 'create-cpt-item must resolve the custom-namespace route, not return rest_no_route.' );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( self::POST_TYPE, $result['type'] );
		$this->assertSame( 'New widget', $result['title'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_list_resolves_custom_rest_namespace(): void {
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

		$this->assertIsArray( $result, 'list-cpt-items must resolve the custom-namespace route, not return rest_no_route.' );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_update_resolves_custom_rest_namespace(): void {
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

		$this->assertIsArray( $result, 'update-cpt-item must resolve the custom-namespace route, not return rest_no_route.' );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'After', $result['title'] );
	}

	public function test_update_cpt_item_points_at_post_editor_screen(): void {
		$ability = wp_get_ability( 'content/update-cpt-item' );

		$this->assertNotNull( $ability );

		$meta = $ability->get_meta();

		$this->assertArrayHasKey( 'screen', $meta );
		$this->assertSame( 'post.php?post={id}&action=edit', $meta['screen'] );
	}

	public function test_delete_resolves_custom_rest_namespace(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Doomed widget',
			)
		);

		$result = wp_get_ability( 'content/delete-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'id'        => $post_id,
			)
		);

		$this->assertIsArray( $result, 'delete-cpt-item must resolve the custom-namespace route, not return rest_no_route.' );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertNull( get_post( $post_id ) );
	}
}
