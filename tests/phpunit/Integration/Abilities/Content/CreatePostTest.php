<?php
/**
 * Integration tests for the og-content/create-post ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the adapter-backed create-post write ability end-to-end: curated
 * input in, shaped field set out, with permission delegated to the wrapped
 * route's own create check on execute().
 */
final class CreatePostTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/create-post' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/create-post', $ability->get_name() );
	}

	public function test_admin_creates_post_and_gets_flat_fields(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/create-post' )->execute(
			array(
				'title'   => 'Draft one',
				'content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Draft one', $result['title'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertNotEmpty( $result['edit_link'] );
		$this->assertStringContainsString( 'post.php', $result['edit_link'] );
		$this->assertStringContainsString( (string) $result['id'], $result['edit_link'] );
	}

	public function test_admin_create_returns_slug_featured_media_and_terms(): void {
		$this->actingAs( 'administrator' );

		$category_id = self::factory()->category->create();
		$tag_id      = self::factory()->tag->create();
		$media_id    = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);

		$result = wp_get_ability( 'og-content/create-post' )->execute(
			array(
				'title'          => 'Tagged draft',
				'content'        => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'slug'           => 'tagged-draft',
				'categories'     => array( $category_id ),
				'tags'           => array( $tag_id ),
				'featured_media' => $media_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'tagged-draft', $result['slug'] );
		$this->assertSame( $media_id, $result['featured_media'] );
		$this->assertSame( array( $category_id ), $result['categories'] );
		$this->assertSame( array( $tag_id ), $result['tags'] );
	}

	public function test_subscriber_is_denied_with_real_rest_error(): void {
		// Permission delegates to the wrapped route, which now runs at dispatch, so
		// execute() surfaces the route's REAL error — not the generic collapse. This
		// ability has no require_permission guard, so check_permissions() is guard-only
		// (returns true); the denial lives on the execute() path. A subscriber lacks the
		// create_posts capability, so the route returns rest_cannot_create (403).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-content/create-post' )->execute(
			array( 'title' => 'Not allowed' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
