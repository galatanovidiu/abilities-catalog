<?php
/**
 * Integration tests for the og-content/delete-post ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises a representative destructive write ability end-to-end: real
 * WordPress data in, the catalog's flat { deleted, id, title } set out, with the
 * wrapped route's object-level capability check enforced at execute().
 */
final class DeletePostTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-content/delete-post' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-content/delete-post', $ability->get_name() );
	}

	public function test_admin_permanently_deletes_post_and_gets_flat_shape(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_title' => 'Doomed' ) );

		$result = wp_get_ability( 'og-content/delete-post' )->execute( array( 'id' => $post_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $post_id, $result['id'] );
		$this->assertSame( 'Doomed', $result['title'] );
		$this->assertArrayNotHasKey( 'edit_link', $result );
		// force=true was injected: the post is gone, not merely trashed.
		$this->assertNull( get_post( $post_id ) );
	}

	public function test_negative_id_fails_validation_without_deleting(): void {
		$this->actingAs( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// A negative id must be rejected by input validation (minimum: 1), never
		// mapped to a positive post and permanently deleted.
		$result = wp_get_ability( 'og-content/delete-post' )->execute( array( 'id' => -$post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_missing_post_surfaces_real_route_404(): void {
		// The route runs at dispatch, so execute() surfaces the route's REAL error —
		// not the generic ability_invalid_permissions collapse.
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-content/delete-post' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_subscriber_is_denied_with_real_route_error(): void {
		// The route enforces the object-level delete_post capability itself and now
		// runs at dispatch, so execute() surfaces the route's REAL error (a 403),
		// not the generic ability_invalid_permissions collapse. This ability sets no
		// require_permission guard, so the denial lives entirely on the execute() path.
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-content/delete-post' )->execute( array( 'id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}
}
