<?php
/**
 * Integration tests for the og-content/update-post ability.
 *
 * Covers registration, presence-based forwarding so a present key with an empty
 * value reaches core (blanking the title, detaching the featured image with 0,
 * clearing assigned terms with []), and object-level denial surfacing the wrapped
 * route's real REST error through execute().
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-content/update-post end-to-end: real WordPress data in, shaped
 * field set out, clear-field semantics, and the wrapped route's capability guard
 * enforced on execute().
 */
final class UpdatePostTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/update-post' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/update-post', $ability->get_name() );
	}

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

	public function test_subscriber_is_denied_with_real_route_error(): void {
		// The wrapped route enforces the object-level edit_post check itself and now
		// runs at dispatch, so execute() surfaces the route's REAL error — not the
		// generic collapse. The adapter's permission phase is guard-only and this
		// ability has no require_permission guard, so check_permissions() would just
		// return true; the denial lives on the execute() path.
		$this->actingAs( 'subscriber' );

		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'og-content/update-post' )->execute(
			array(
				'id'    => $post_id,
				'title' => 'Subscriber edit attempt',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
