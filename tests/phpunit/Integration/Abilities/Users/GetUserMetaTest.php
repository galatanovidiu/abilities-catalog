<?php
/**
 * Integration tests for the og-users/get-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-users/get-meta end-to-end against a registered show_in_rest user
 * meta key, plus the registered-key gate (the security property) and the
 * object-level permission guard.
 */
final class GetUserMetaTest extends TestCase {

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

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		unregister_meta_key( 'user', 'abilities_catalog_test_key' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-users/get-meta' ) );
	}

	public function test_happy_path_returns_registered_meta(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'hello' );

		$result = wp_get_ability( 'og-users/get-meta' )->execute( array( 'id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->user_id, $result['id'] );
		$this->assertIsObject( $result['meta'] );
		$meta = (array) $result['meta'];
		$this->assertArrayHasKey( 'abilities_catalog_test_key', $meta );
		$this->assertSame( 'hello', $meta['abilities_catalog_test_key'] );
	}

	public function test_unregistered_key_is_silently_dropped(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'present' );

		// wp_capabilities is internal/unregistered; a _-prefixed key is unknown.
		$result = wp_get_ability( 'og-users/get-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'keys' => array( 'abilities_catalog_test_key', 'wp_capabilities', 'session_tokens' ),
			)
		);

		$this->assertIsArray( $result );
		$meta = (array) $result['meta'];
		$this->assertSame( array( 'abilities_catalog_test_key' ), array_keys( $meta ) );
		$this->assertArrayNotHasKey( 'wp_capabilities', $meta );
		$this->assertArrayNotHasKey( 'session_tokens', $meta );
	}

	public function test_missing_user_returns_invalid_id_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/get-meta' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-users/get-meta' )->execute( array( 'id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		// A logged-out user cannot edit the user, so the object-level guard returns
		// a specific 403 rather than the generic permission collapse.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_user_without_edit_user_gets_403_not_404(): void {
		$this->actingAs( 'subscriber' );

		// The target user exists, so a subscriber lacking edit_user must get a 403,
		// not the 404 reserved for a missing user.
		$result = wp_get_ability( 'og-users/get-meta' )->execute( array( 'id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_user_invalid_id', $result->get_error_code() );
	}

	public function test_output_shape_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/get-meta' )->execute( array( 'id' => $this->user_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'meta' ), array_keys( $result ) );
	}
}
