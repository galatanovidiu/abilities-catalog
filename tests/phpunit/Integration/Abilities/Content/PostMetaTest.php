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

		// Gap (a): object-wide (empty-subtype) meta. register_meta without a
		// subtype lands in $wp_meta_keys['post'][''], which core REST merges into
		// every post subtype but get_registered_meta_keys('post', $post_type) omits.
		register_meta(
			'post',
			'objectwide_note',
			array(
				'object_subtype' => '',
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
				'description'    => 'An object-wide note.',
			)
		);

		// Gap (c): a storage key exposed under a different public REST name via
		// show_in_rest['name']. Core reads/writes under the alias but stores under
		// the storage key.
		register_post_meta(
			'post',
			'aliased_storage_key',
			array(
				'show_in_rest' => array( 'name' => 'public_alias' ),
				'single'       => true,
				'type'         => 'string',
				'description'  => 'A key exposed under an alias.',
			)
		);

		// Gap (d): a boolean key. get_post_meta returns the stored "1"/""; core
		// REST casts it through the schema to a real boolean.
		register_post_meta(
			'post',
			'is_featured',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'boolean',
				'description'  => 'Whether the post is featured.',
			)
		);

		$this->post_id = self::factory()->post->create();
	}

	public function tear_down(): void {
		unregister_post_meta( 'post', 'subtitle' );
		unregister_post_meta( 'post', 'internal_flag' );
		unregister_post_meta( 'post', 'guarded_meta' );
		unregister_meta_key( 'post', 'objectwide_note', '' );
		unregister_post_meta( 'post', 'aliased_storage_key' );
		unregister_post_meta( 'post', 'is_featured' );
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

	/**
	 * Gap (a): object-wide (empty-subtype) meta must be visible to all four
	 * abilities. Core REST merges $wp_meta_keys['post'][''] into every subtype.
	 */
	public function test_object_wide_meta_is_listed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'post' ) );

		$keys = wp_list_pluck( $result['keys'], 'key' );
		$this->assertContains( 'objectwide_note', $keys, 'Object-wide meta must be listed for the post type.' );
	}

	public function test_object_wide_meta_roundtrip(): void {
		$this->actingAs( 'administrator' );

		$updated = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'objectwide_note' => 'noted' ),
			)
		);

		$this->assertIsArray( $updated, 'Object-wide meta must be writable, not rejected as unknown.' );
		$this->assertSame( 'noted', get_post_meta( $this->post_id, 'objectwide_note', true ) );

		$got = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );
		$this->assertSame( 'noted', ( (array) $got['meta'] )['objectwide_note'] );

		$deleted = wp_get_ability( 'content/delete-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'objectwide_note' ),
			)
		);
		$this->assertIsArray( $deleted );
		$this->assertSame( array( 'objectwide_note' ), $deleted['deleted'] );
		$this->assertSame( '', get_post_meta( $this->post_id, 'objectwide_note', true ) );
	}

	/**
	 * Gap (b): meta is only exposed when the post type supports custom-fields,
	 * matching core's posts-controller gate. `attachment` does not support it.
	 */
	public function test_custom_fields_gate_hides_meta_for_unsupported_type(): void {
		$this->actingAs( 'administrator' );

		register_post_meta(
			'attachment',
			'att_meta',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);

		$result = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'attachment' ) );

		$keys = wp_list_pluck( $result['keys'], 'key' );
		$this->assertNotContains(
			'att_meta',
			$keys,
			'Attachment does not support custom-fields, so its meta must not be exposed.'
		);

		unregister_post_meta( 'attachment', 'att_meta' );
	}

	/**
	 * Gap (c): a key with show_in_rest['name'] is read and written under the
	 * public alias, while storage uses the underlying meta key.
	 */
	public function test_alias_name_is_used_for_read_and_write(): void {
		$this->actingAs( 'administrator' );

		// Listed under the public alias, not the storage key.
		$listed = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'post' ) );
		$keys   = wp_list_pluck( $listed['keys'], 'key' );
		$this->assertContains( 'public_alias', $keys );
		$this->assertNotContains( 'aliased_storage_key', $keys );

		// Write under the alias; storage lands on the underlying key.
		$updated = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'public_alias' => 'aliased value' ),
			)
		);
		$this->assertIsArray( $updated );
		$this->assertSame( 'aliased value', ( (array) $updated['meta'] )['public_alias'] );
		$this->assertSame( 'aliased value', get_post_meta( $this->post_id, 'aliased_storage_key', true ) );

		// Read returns it under the alias.
		$got  = wp_get_ability( 'content/get-post-meta' )->execute( array( 'id' => $this->post_id ) );
		$meta = (array) $got['meta'];
		$this->assertArrayHasKey( 'public_alias', $meta );
		$this->assertArrayNotHasKey( 'aliased_storage_key', $meta );
		$this->assertSame( 'aliased value', $meta['public_alias'] );

		// Writing under the raw storage key is rejected as unknown.
		$rejected = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'aliased_storage_key' => 'x' ),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'rest_post_meta_unknown_key', $rejected->get_error_code() );

		// Delete under the alias clears the storage key.
		$deleted = wp_get_ability( 'content/delete-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'public_alias' ),
			)
		);
		$this->assertIsArray( $deleted );
		$this->assertSame( array( 'public_alias' ), $deleted['deleted'] );
		$this->assertSame( '', get_post_meta( $this->post_id, 'aliased_storage_key', true ) );
	}

	/**
	 * Gap (d): returned values are cast through the registered schema. A boolean
	 * stored as the string "1" must come back as a real boolean true.
	 */
	public function test_get_casts_value_through_schema(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'is_featured', '1' );

		$got  = wp_get_ability( 'content/get-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'keys' => array( 'is_featured' ),
			)
		);
		$meta = (array) $got['meta'];

		$this->assertArrayHasKey( 'is_featured', $meta );
		$this->assertTrue( $meta['is_featured'], 'A boolean meta stored as "1" must be cast to true.' );
		$this->assertIsBool( $meta['is_featured'] );
	}

	public function test_update_returns_cast_value(): void {
		$this->actingAs( 'administrator' );

		$updated = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'is_featured' => true ),
			)
		);

		$this->assertIsArray( $updated );
		$applied = (array) $updated['meta'];
		$this->assertTrue( $applied['is_featured'], 'Applied boolean meta must be returned as true.' );
		$this->assertIsBool( $applied['is_featured'] );
	}

	/**
	 * A failed write (filter short-circuit or DB error) must surface as a 500
	 * error, not a success carrying the stale stored value.
	 */
	public function test_update_failed_write_returns_database_error(): void {
		$this->actingAs( 'administrator' );

		// Short-circuit the write so update_post_meta() returns false even though
		// the new value differs from the stored one.
		$short_circuit = static function ( $check, $object_id, $meta_key ) {
			return 'subtitle' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $short_circuit, 10, 3 );

		$result = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'subtitle' => 'never written' ),
			)
		);

		remove_filter( 'update_post_metadata', $short_circuit, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_database_error', $result->get_error_code() );
		$this->assertSame( 500, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertSame( 'subtitle', $result->get_error_data()['key'] ?? null );
		// Nothing was written.
		$this->assertSame( '', get_post_meta( $this->post_id, 'subtitle', true ) );
	}

	/**
	 * Setting a key to its current value is a no-op for update_post_meta() (it
	 * returns false), but it is not a failure — the ability must report success.
	 */
	public function test_update_unchanged_value_is_success(): void {
		$this->actingAs( 'administrator' );
		update_post_meta( $this->post_id, 'subtitle', 'same value' );

		$result = wp_get_ability( 'content/update-post-meta' )->execute(
			array(
				'id'   => $this->post_id,
				'meta' => array( 'subtitle' => 'same value' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'same value', ( (array) $result['meta'] )['subtitle'] );
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

	public function test_list_unknown_post_type_returns_invalid_post_type(): void {
		$this->actingAs( 'administrator' );

		// An unregistered post type passes the permission gate so execute() can
		// return a specific 400 error instead of a generic permission collapse.
		$result = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'no_such_type' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_list_denies_user_without_edit_capability(): void {
		$this->actingAs( 'subscriber' );

		// A registered type the user cannot edit fails the capability guard, so
		// core collapses it to the generic permission error — distinct from the
		// 400 invalid_post_type returned for an unknown type.
		$result = wp_get_ability( 'content/list-post-meta-keys' )->execute( array( 'post_type' => 'post' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'invalid_post_type', $result->get_error_code() );
	}
}
