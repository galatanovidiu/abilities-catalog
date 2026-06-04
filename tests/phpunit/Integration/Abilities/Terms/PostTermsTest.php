<?php
/**
 * Integration tests for the post-term assignment abilities.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises attach/detach post terms end-to-end, including resolving terms by
 * ID and slug, the missing-term error, and the permission guard.
 */
final class PostTermsTest extends TestCase {

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Existing category term IDs.
	 *
	 * @var int
	 */
	private int $cat_a;

	/**
	 * Second category term ID.
	 *
	 * @var int
	 */
	private int $cat_b;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = self::factory()->post->create();
		$this->cat_a   = self::factory()->category->create(
			array(
				'slug' => 'cat-a',
				'name' => 'Cat A',
			)
		);
		$this->cat_b   = self::factory()->category->create(
			array(
				'slug' => 'cat-b',
				'name' => 'Cat B',
			)
		);
	}

	public function test_abilities_are_registered(): void {
		$this->assertNotNull( wp_get_ability( 'terms/attach-post-terms' ) );
		$this->assertNotNull( wp_get_ability( 'terms/detach-post-terms' ) );
	}

	public function test_attach_by_id_and_slug_appends(): void {
		$this->actingAs( 'administrator' );

		$first = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);
		$this->assertIsArray( $first );
		$this->assertContains( $this->cat_a, $first['term_ids'] );
		$this->assertNotEmpty( $first['edit_link'] );

		// Append the second term by slug.
		$second = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( 'cat-b' ),
			)
		);
		$this->assertContains( $this->cat_a, $second['term_ids'] );
		$this->assertContains( $this->cat_b, $second['term_ids'] );
	}

	public function test_attach_missing_term_returns_error(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( 'does-not-exist' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_term_not_found', $result->get_error_code() );
	}

	public function test_detach_removes_only_named_terms(): void {
		$this->actingAs( 'administrator' );
		wp_set_object_terms( $this->post_id, array( $this->cat_a, $this->cat_b ), 'category' );

		$result = wp_get_ability( 'terms/detach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotContains( $this->cat_a, $result['term_ids'] );
		$this->assertContains( $this->cat_b, $result['term_ids'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
