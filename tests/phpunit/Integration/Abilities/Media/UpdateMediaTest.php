<?php
/**
 * Integration tests for the og-media/update-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/update-media: flat output shape after a real update,
 * the source_url and post fields in the result, and the minimum: 1 input guard.
 */
final class UpdateMediaTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-media/update-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-media/update-media', $ability->get_name() );
	}

	public function test_admin_updates_media_and_gets_flat_fields(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/update-media' )->execute(
			array(
				'id'          => $attachment_id,
				'title'       => 'Updated Canola',
				'alt_text'    => 'Updated alt',
				'caption'     => 'Updated caption',
				'description' => 'Updated description',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertSame( 'Updated Canola', $result['title'] );
		$this->assertSame( 'Updated alt', $result['alt_text'] );
		$this->assertStringContainsString( 'Updated caption', $result['caption'] );
		$this->assertStringContainsString( 'Updated description', $result['description'] );
		$this->assertNotEmpty( $result['source_url'] );
		$this->assertSame( 0, $result['post'] );
	}

	public function test_attach_to_post_is_reflected_in_output(): void {
		$this->actingAs( 'administrator' );

		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		$result = wp_get_ability( 'og-media/update-media' )->execute(
			array(
				'id'   => $attachment_id,
				'post' => $post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post'] );
	}

	public function test_zero_post_detaches_the_attachment_parent(): void {
		$this->actingAs( 'administrator' );

		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create( array( 'post_parent' => $post_id ) );
		$this->assertSame( $post_id, (int) get_post( $attachment_id )->post_parent );

		$result = wp_get_ability( 'og-media/update-media' )->execute(
			array(
				'id'   => $attachment_id,
				'post' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['post'] );
		$this->assertSame( 0, (int) get_post( $attachment_id )->post_parent );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		// The minimum: 1 input guard rejects a non-positive id at the schema
		// boundary, before absint() could retarget it to a different object.
		$result = wp_get_ability( 'og-media/update-media' )->execute( array( 'id' => -7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_author_updating_missing_media_surfaces_route_404_not_generic(): void {
		// Adapter-backed: the ability has no require_permission floor, so the permission
		// phase returns true and the wrapped route runs at dispatch. A non-existent id
		// surfaces the route's specific invalid-id 404 instead of the opaque
		// ability_invalid_permissions an object-level pre-check produced.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'og-media/update-media' )->execute(
			array(
				'id'    => 999999,
				'title' => 'x',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}

	public function test_author_cannot_update_other_users_media_route_403_no_mutation(): void {
		$owner_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = self::factory()->attachment->create(
			array(
				'post_author' => $owner_id,
				'post_title'  => 'Original',
			)
		);

		// The author lacks edit_others_posts, so the wrapped route's object-level check
		// denies at dispatch — by a specific 403, not the generic collapse — and the
		// title is unchanged. The adapter's permission phase is guard-only (no
		// require_permission here), so the denial lives on the execute() path.
		$this->actingAs( 'author' );

		$result = wp_get_ability( 'og-media/update-media' )->execute(
			array(
				'id'    => $attachment_id,
				'title' => 'Hacked',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 'Original', get_post( $attachment_id )->post_title );
	}
}
