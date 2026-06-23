<?php
/**
 * Integration tests for the users/delete-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises users/delete-meta end-to-end against a registered show_in_rest user
 * meta key, plus the registered-key gate (the security property), the object-level
 * permission guard, and the per-key delete capability.
 */
final class DeleteUserMetaTest extends TestCase {

	/**
	 * User under test.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		register_meta(
			'user',
			'abilities_catalog_test_key',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
			)
		);

		// A registered key whose auth_callback allows edit but denies delete, so the
		// per-key delete_user_meta check fails for a user who can otherwise edit.
		register_meta(
			'user',
			'abilities_catalog_guarded_key',
			array(
				'object_subtype' => 'user',
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
				'auth_callback'  => static function ( $allowed, $meta_key, $object_id, $user_id, $cap ) {
					return 'delete_user_meta' !== $cap; // Allow edit, deny delete.
				},
			)
		);

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		unregister_meta_key( 'user', 'abilities_catalog_test_key' );
		unregister_meta_key( 'user', 'abilities_catalog_guarded_key', 'user' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'users/delete-meta' ) );
	}

	public function test_happy_path_removes_key(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'to be removed' );

		$result = wp_get_ability( 'users/delete-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'abilities_catalog_test_key' ), $result['deleted'] );
		// Read back empty via the generic metadata function.
		$this->assertSame( '', get_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_unregistered_key_is_rejected_and_nothing_deleted(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'survives' );

		// wp_capabilities is internal/unregistered — deleting it would corrupt the
		// user's roles; it must be rejected as an unknown key.
		$result = wp_get_ability( 'users/delete-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'keys' => array( 'abilities_catalog_test_key', 'wp_capabilities' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		// Validation is all-or-nothing: the registered key was NOT deleted either.
		$this->assertSame( 'survives', get_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_missing_user_returns_invalid_id_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/delete-meta' )->execute(
			array(
				'id'   => 999999,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_value_survives(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'still here' );
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'users/delete-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// A logged-out caller cannot edit the user; the object-level guard denies it
		// rather than deleting anything.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 'still here', get_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_user_without_delete_user_meta_gets_403(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_guarded_key', 'guarded' );

		// The administrator can edit the user (passes the object guard) but the
		// auth_callback denies delete_user_meta for this key, so the per-key check
		// fails with a specific 403 — and the value survives.
		$result = wp_get_ability( 'users/delete-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'keys' => array( 'abilities_catalog_guarded_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_delete_meta', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 'guarded', get_metadata( 'user', $this->user_id, 'abilities_catalog_guarded_key', true ) );
	}

	public function test_output_shape_is_exact(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'x' );

		$result = wp_get_ability( 'users/delete-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'deleted', 'edit_link' ), array_keys( $result ) );
	}
}
