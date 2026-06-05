<?php
/**
 * Integration tests for D5/D6: list abilities return shaped summary rows.
 *
 * Proves the content list abilities project each item to a flat summary (no
 * content body, no `_links`/`guid`/`class_list`/GMT internals), report correct
 * totals, and that list-cpt-items no longer collapses an unknown post type into
 * a permission error.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the output shape of the content list abilities.
 */
final class ListShapeTest extends TestCase {

	private const INTERNAL_KEYS = array( '_links', 'guid', 'class_list', 'date_gmt', 'modified_gmt', 'content', 'yoast_head' );

	private function assertShapedRow( array $row ): void {
		foreach ( self::INTERNAL_KEYS as $leaked ) {
			$this->assertArrayNotHasKey( $leaked, $row, "List row leaked internal key: {$leaked}" );
		}
		$this->assertArrayHasKey( 'id', $row );
		$this->assertArrayHasKey( 'title', $row );
		$this->assertIsString( $row['title'] );
		$this->assertArrayHasKey( 'edit_link', $row );
	}

	public function test_list_posts_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'content/list-posts' )->execute( array( 'per_page' => 10 ) );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
		$this->assertGreaterThan( 0, $result['total'] );
		foreach ( $result['items'] as $row ) {
			$this->assertShapedRow( $row );
		}
	}

	public function test_list_pages_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'content/list-pages' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertShapedRow( $row );
		}
	}

	public function test_list_cpt_items_excludes_body_and_reports_total(): void {
		$this->actingAs( 'administrator' );
		self::factory()->post->create_many( 2, array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'content/list-cpt-items' )->execute(
			array(
				'post_type' => 'post',
				'per_page'  => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
		$this->assertGreaterThan( 0, $result['total'] );
		foreach ( $result['items'] as $row ) {
			$this->assertShapedRow( $row );
			$this->assertSame( 'post', $row['type'] );
		}
	}

	public function test_list_cpt_items_unknown_type_returns_400_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-cpt-items' )->execute( array( 'post_type' => 'not_a_real_type' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}

	public function test_list_post_revisions_returns_shaped_rows(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_content' => 'v1' ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'v2',
			)
		);

		$result = wp_get_ability( 'content/list-post-revisions' )->execute( array( 'parent' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );
		foreach ( $result['items'] as $row ) {
			$this->assertArrayNotHasKey( '_links', $row );
			$this->assertArrayNotHasKey( 'guid', $row );
			$this->assertArrayHasKey( 'parent', $row );
			$this->assertSame( $post_id, $row['parent'] );
		}
	}

	public function test_list_post_types_reports_supports_as_flat_list(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-post-types' )->execute( array() );

		$this->assertIsArray( $result );
		$post_row = null;
		foreach ( $result['items'] as $row ) {
			if ( 'post' === $row['slug'] ) {
				$post_row = $row;
				break;
			}
		}
		$this->assertNotNull( $post_row, 'The "post" type should be listed.' );
		$this->assertIsArray( $post_row['supports'] );
		$this->assertContains( 'editor', $post_row['supports'] );
		// Flat list of feature keys, not the nested REST shape.
		$this->assertArrayHasKey( 0, $post_row['supports'] );
	}
}
