<?php
/**
 * Integration tests for the og-media/get-media ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Media;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-media/get-media end-to-end: flat output shape over a real upload,
 * the metadata-less object cast for media_details, the minimum: 1 input guard, and
 * the route's real permission errors surfaced through execute() by the adapter.
 */
final class GetMediaTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-media/get-media' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-media/get-media', $ability->get_name() );
	}

	public function test_admin_reads_media_with_flat_fields(): void {
		$this->actingAs( 'administrator' );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $attachment_id );

		wp_update_post(
			array(
				'ID'         => $attachment_id,
				'post_title' => 'Canola Field',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'A canola field' );

		$result = wp_get_ability( 'og-media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertSame( 'Canola Field', $result['title'] );
		$this->assertSame( 'A canola field', $result['alt_text'] );
		$this->assertSame( 'image', $result['media_type'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertNotEmpty( $result['source_url'] );
		// A real image carries metadata, so media_details is a populated array.
		$this->assertIsArray( $result['media_details'] );
		$this->assertArrayHasKey( 'width', $result['media_details'] );
	}

	public function test_metadata_less_attachment_returns_media_details_as_object(): void {
		$this->actingAs( 'administrator' );

		// A bare attachment with no generated metadata: core supplies an empty
		// stdClass for media_details, which the ability must keep as an object so
		// it serializes to {} (not []) under the type: object output schema.
		$attachment_id = self::factory()->attachment->create();

		$result = wp_get_ability( 'og-media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertIsObject( $result['media_details'] );
		$this->assertSame( '{}', wp_json_encode( $result['media_details'] ) );
	}

	public function test_unattached_media_returns_post_null(): void {
		$this->actingAs( 'administrator' );

		// A bare attachment with no parent: core emits post=null, which the ability
		// must preserve as null rather than flattening to 0.
		$attachment_id = self::factory()->attachment->create();

		$result = wp_get_ability( 'og-media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post', $result );
		$this->assertNull( $result['post'] );
	}

	public function test_attached_media_returns_parent_id(): void {
		$this->actingAs( 'administrator' );

		$parent_id     = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create( array( 'post_parent' => $parent_id ) );

		$result = wp_get_ability( 'og-media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $parent_id, $result['post'] );
	}

	public function test_negative_id_does_not_route(): void {
		$this->actingAs( 'administrator' );

		// Adapter-backed: the derived input schema types `id` from the `[\d]+` path
		// capture (integer, no `minimum`), so a negative id passes the ability's own
		// validation and reaches the route at dispatch. There it cannot fit the numeric
		// capture, so no REST route matches the path and the adapter returns rest_no_route
		// (404) — the same verdict the request would get over HTTP — instead of the old
		// hand-written schema's `minimum: 1` rejection.
		$result = wp_get_ability( 'og-media/get-media' )->execute( array( 'id' => -7 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_edit_context_missing_id_surfaces_route_404_not_generic_denial(): void {
		$this->actingAs( 'administrator' );

		// Adapter-backed: the route runs at dispatch, so a non-existent id in edit
		// context reaches it and returns its specific invalid-id 404 instead of the
		// opaque ability_invalid_permissions an object-level pre-check would produce.
		// The ability sets no require_permission guard, so nothing collapses the error.
		$result = wp_get_ability( 'og-media/get-media' )->execute(
			array(
				'id'      => 999999,
				'context' => 'edit',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}

	public function test_subscriber_denied_edit_context_gets_route_forbidden_not_generic(): void {
		$author_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = self::factory()->attachment->create( array( 'post_author' => $author_id ) );

		// A low-privilege user requesting edit context is denied — but by the route's
		// specific rest_forbidden_context 403, surfaced through execute() at dispatch.
		// The adapter's permission phase is guard-only and this ability sets no
		// require_permission guard, so the denial is the route's, not the generic
		// ability_invalid_permissions collapse.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-media/get-media' )->execute(
			array(
				'id'      => $attachment_id,
				'context' => 'edit',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_forbidden_context', $result->get_error_code() );
	}

	public function test_logged_out_can_read_published_attached_media(): void {
		$parent_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$parent_id
		);
		$this->assertIsInt( $attachment_id );

		// Core allows anonymous reads of an inherit-status attachment whose parent is
		// published. The ability must not be stricter than core: permission delegates to
		// the wrapped route (no require_permission floor), so the logged-out read succeeds.
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-media/get-media' )->execute( array( 'id' => $attachment_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertNotEmpty( $result['source_url'] );
	}
}
