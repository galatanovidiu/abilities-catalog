<?php
/**
 * Integration tests for og-content/get-cpt-item route resolution.
 *
 * Proves the ability resolves a post type's REST item route via
 * rest_get_route_for_post_type_items(), so a CPT registered with a custom
 * rest_namespace is read from its real route instead of a hardcoded /wp/v2 path.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises route resolution for the generic CPT reader.
 */
final class GetCptItemRouteTest extends TestCase {

	private const POST_TYPE = 'catalog_widget';

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'catalog_widget',
			array(
				'public'         => true,
				'show_in_rest'   => true,
				'rest_base'      => 'widgets',
				'rest_namespace' => 'catalog/v1',
				'supports'       => array( 'title', 'editor', 'author', 'excerpt' ),
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

	public function test_reads_item_from_custom_rest_namespace(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Widget one',
			)
		);

		$result = wp_get_ability( 'og-content/get-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'id'        => $post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( self::POST_TYPE, $result['type'] );
		$this->assertSame( 'Widget one', $result['title'] );
	}

	public function test_forwards_password_for_protected_item(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_type'     => self::POST_TYPE,
				'post_status'   => 'publish',
				'post_title'    => 'Locked widget',
				'post_content'  => 'Secret body',
				'post_password' => 'letmein',
			)
		);

		wp_set_current_user( 0 );

		$without = wp_get_ability( 'og-content/get-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'id'        => $post_id,
			)
		);
		$this->assertIsArray( $without );
		$this->assertSame( '', $without['content'] );

		$with = wp_get_ability( 'og-content/get-cpt-item' )->execute(
			array(
				'post_type' => self::POST_TYPE,
				'id'        => $post_id,
				'password'  => 'letmein',
			)
		);
		$this->assertIsArray( $with );
		$this->assertStringContainsString( 'Secret body', $with['content'] );
	}
}
