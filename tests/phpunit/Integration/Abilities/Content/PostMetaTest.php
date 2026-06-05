<?php
/**
 * Integration tests for the post-meta abilities.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises get/update/delete/list post-meta end-to-end against a registered
 * show_in_rest meta key, plus the registered-key gate and the permission guard.
 */
final class PostMetaTest extends TestCase {

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		register_post_meta(
			'post',
			'subtitle',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'description'  => 'A post subtitle.',
			)
		);

		// A key that is NOT exposed to REST — must never be reachable.
		register_post_meta(
			'post',
			'internal_flag',
			array(
				'show_in_rest' => false,
				'single'       => true,
				'type'         => 'string',
			)
		);

		// A subtype-less key whose auth_callback allows edit but denies delete.
		// Registered against object subtype '' so the generic
		// `auth_post_meta_guarded_meta` filter fires (a CPT subtype would route
		// to `_for_{subtype}` and exercise the wrong capability path).
		register_post_meta(
			'post',
			'guarded_meta',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => static function ( $allowed, $meta_key, $object_id, $user_id, $cap ) {
					return 'delete_post_meta' !== $cap; // Allow edit, deny delete.
				},
			)
		);

		$this->post_id = self::factory()->post->create();
	}

	public function tear_down(): void {
		unregister_post_meta( 'post', 'subtitle' );
		unregister_post_meta( 'post', 'internal_flag' );
		unregister_post_meta( 'post', 'guarded_meta' );
		parent::tear_down();
	}

	public function test_abilities_are_registered(): void {
		$this->assertNotNull( wp_get_ability( 'content/get-post-meta' ) );
		$this->assertNotNull( wp_get_ability( 'content/update-post-meta' ) );
		$this->assertNotNull( wp_get_ability( 'content/delete-post-meta' ) );
		$this->assertNotNull( wp_get_ability( 'content/list-post-meta-keys' ) );
	}

	public function test_list_returns_only_show_in_rest_keys(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'post' ) );

		$this->assertIsArray( $result );
		$keys = wp_list_pluck( $result['keys'], 'key' );
		$this->assertContains( 'subtitle', $keys );
		$this->assertNotContains( 'internal_flag', $keys );
	}

	public function test_update_then_get_roundtrip(): void {
		$this->actingAs( 'administrator' );

		$updated = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'subtitle' => 'Hello world' ),
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( 'Hello world', ( (array) $updated['meta'] )['subtitle'] );
		$this->assertNotEmpty( $updated['edit_link'] );
		$this->assertSame( 'Hello world', get_post_meta( $this->post_id, 'subtitle', true ) );

		$got = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );
		$this->assertSame( 'Hello world', ( (array) $got['meta'] )['subtitle'] );
	}

	public function test_get_returns_meta_object_with_requested_keys(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'subtitle', 'A subtitle' );

		$got = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );

		$this->assertIsArray( $got );
		$this->assertSame( $this->post_id, $got['id'] );
		$this->assertIsObject( $got['meta'] );
		$meta = (array) $got['meta'];
		$this->assertArrayHasKey( 'subtitle', $meta );
		$this->assertSame( 'A subtitle', $meta['subtitle'] );
	}

	public function test_get_missing_post_returns_invalid_id(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_get_keys_filter_returns_subset(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'subtitle', 'Only me' );

		$got = wp_get_ability( 'content/get-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'subtitle' ),
			)
		);

		$this->assertIsArray( $got );
		$this->assertSame( array( 'subtitle' ), array_keys( (array) $got['meta'] ) );
	}

	public function test_get_off_list_requested_key_is_dropped(): void {
		$this->actingAs( 'administrator' );

		$got = wp_get_ability( 'content/get-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'subtitle', 'internal_flag', 'does_not_exist' ),
			)
		);

		$this->assertIsArray( $got );
		$meta = (array) $got['meta'];
		$this->assertArrayNotHasKey( 'internal_flag', $meta );
		$this->assertArrayNotHasKey( 'does_not_exist', $meta );
		$this->assertArrayHasKey( 'subtitle', $meta );
	}

	public function test_update_rejects_unregistered_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'internal_flag' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_meta_unknown_key', $result->get_error_code() );
		// Nothing was written.
		$this->assertSame( '', get_post_meta( $this->post_id, 'internal_flag', true ) );
	}

	public function test_delete_removes_key(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'subtitle', 'to be removed' );

		$result = wp_get_ability( 'content/delete-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'subtitle' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'subtitle' ), $result['deleted'] );
		$this->assertSame( '', get_post_meta( $this->post_id, 'subtitle', true ) );
	}

	public function test_delete_respects_delete_post_meta_capability(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'guarded_meta', 'x' );

		// Delete is gated on `delete_post_meta`, which the auth_callback denies.
		$result = wp_get_ability( 'content/delete-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'guarded_meta' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_delete_post_meta', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// The same key is editable: update is gated on `edit_post_meta`, which the
		// auth_callback allows. This proves the divergence is cap-driven, not a
		// blanket denial.
		$updated = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'guarded_meta' => 'y' ),
			)
		);

		$this->assertIsArray( $updated );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		// A logged-out user cannot edit the post, so the object-level guard in
		// execute() returns a specific authorization error (401 when unauthenticated)
		// rather than the generic "does not have necessary permission" collapse.
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
