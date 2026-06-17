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

	public function test_attach_replace_mode_swaps_terms(): void {
		$this->actingAs( 'administrator' );
		wp_set_object_terms( $this->post_id, array( $this->cat_a ), 'category' );

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_b ),
				'append'   => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertNotContains( $this->cat_a, $result['term_ids'] );
		$this->assertContains( $this->cat_b, $result['term_ids'] );
	}

	public function test_attach_output_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'post_id', 'taxonomy', 'term_ids', 'edit_link' ),
			array_keys( $result )
		);
		$this->assertSame( $this->post_id, $result['post_id'] );
		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertIsArray( $result['term_ids'] );
		$this->assertContainsOnly( 'int', $result['term_ids'] );
		$this->assertIsString( $result['edit_link'] );
	}

	public function test_attach_taxonomy_not_on_post_type_returns_error(): void {
		$this->actingAs( 'administrator' );

		// Taxonomy exists (so the permission gate passes) but is registered for
		// pages only, not the post type under test.
		register_taxonomy(
			'pages_only_tax',
			array( 'page' ),
			array( 'capabilities' => array( 'assign_terms' => 'edit_posts' ) )
		);
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'pages_only_tax',
				'slug'     => 'pages-term',
			)
		);

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'pages_only_tax',
				'terms'    => array( $term_id ),
			)
		);

		unregister_taxonomy( 'pages_only_tax' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_taxonomy_invalid', $result->get_error_code() );
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

	public function test_detach_output_shape(): void {
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
		$this->assertSame(
			array( 'post_id', 'taxonomy', 'term_ids', 'edit_link' ),
			array_keys( $result )
		);
		$this->assertSame( $this->post_id, $result['post_id'] );
		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertIsArray( $result['term_ids'] );
		$this->assertContainsOnly( 'int', $result['term_ids'] );
		$this->assertIsString( $result['edit_link'] );
	}

	public function test_detach_invalid_post_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// With the object-level edit_post check relocated into execute(), an admin who
		// holds assign_terms reaches execute(), and a non-existent post surfaces the
		// specific rest_post_invalid_id 404 instead of the generic collapse.
		$result = wp_get_ability( 'terms/detach-post-terms' )->execute(
			array(
				'post_id'  => 999999,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_attach_invalid_post_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => 999999,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_attach_to_unowned_post_denied_by_execute_403_no_mutation(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = self::factory()->post->create( array( 'post_author' => $owner_id ) );

		// An author holds assign_categories (the coarse floor) but cannot edit another
		// user's post, so the relocated object guard in execute() denies with a specific
		// 403 and no term is attached — the guard is not weakened by coarsening.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'terms/attach-post-terms' )->execute(
			array(
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertNotContains( $this->cat_a, wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) ) );
	}

	public function test_detach_from_unowned_post_denied_by_execute_403_no_mutation(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = self::factory()->post->create( array( 'post_author' => $owner_id ) );
		wp_set_object_terms( $post_id, array( $this->cat_a ), 'category' );

		// An author cannot edit another user's post, so detach is denied at execute()
		// with a 403 and the existing term assignment is left intact.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'terms/detach-post-terms' )->execute(
			array(
				'post_id'  => $post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_a ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertContains( $this->cat_a, wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) ) );
	}

	public function test_detach_already_absent_term_is_idempotent_no_op(): void {
		$this->actingAs( 'administrator' );
		wp_set_object_terms( $this->post_id, array( $this->cat_a ), 'category' );

		// cat_b is not assigned; detaching it is a successful no-op.
		$result = wp_get_ability( 'terms/detach-post-terms' )->execute(
			array(
				'post_id'  => $this->post_id,
				'taxonomy' => 'category',
				'terms'    => array( $this->cat_b ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertContains( $this->cat_a, $result['term_ids'] );
		$this->assertNotContains( $this->cat_b, $result['term_ids'] );
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

	public function test_detach_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );
		wp_set_object_terms( $this->post_id, array( $this->cat_a ), 'category' );

		$result = wp_get_ability( 'terms/detach-post-terms' )->execute(
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
