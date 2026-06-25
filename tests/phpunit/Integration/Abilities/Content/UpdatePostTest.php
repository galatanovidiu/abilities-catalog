<?php
/**
 * Integration tests for og-content/update-post clear-field semantics.
 *
 * Covers presence-based forwarding so a present key with an empty value
 * reaches core: blanking the title, detaching the featured image with 0,
 * and clearing assigned terms with [].
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises og-content/update-post clear-field behavior.
 */
final class UpdatePostTest extends TestCase {

	public function test_empty_title_clears_the_title(): void {
		$this->actingAs( 'administrator' );

		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Has A Title',
			)
		);

		$result = wp_get_ability( 'og-content/update-post' )->execute(
			array(
				'id'    => $post_id,
				'title' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', get_post( $post_id )->post_title );
	}

	public function test_zero_featured_media_detaches_the_thumbnail(): void {
		$this->actingAs( 'administrator' );

		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );
		set_post_thumbnail( $post_id, $attachment_id );
		$this->assertSame( $attachment_id, get_post_thumbnail_id( $post_id ) );

		$result = wp_get_ability( 'og-content/update-post' )->execute(
			array(
				'id'             => $post_id,
				'featured_media' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post_id ) );
	}

	public function test_empty_categories_array_clears_assigned_terms(): void {
		$this->actingAs( 'administrator' );

		$post_id     = self::factory()->post->create();
		$category_id = self::factory()->category->create();
		wp_set_post_categories( $post_id, array( $category_id ) );
		$this->assertSame( array( $category_id ), wp_get_post_categories( $post_id ) );

		$result = wp_get_ability( 'og-content/update-post' )->execute(
			array(
				'id'         => $post_id,
				'categories' => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array(), wp_get_post_categories( $post_id ) );
	}
}
