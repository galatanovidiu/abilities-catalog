<?php
/**
 * Integration tests for the `og-users/update-meta` ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises `og-users/update-meta` end-to-end against a registered show_in_rest user
 * meta key, plus the registered-key security gate and the object/permission guards.
 */
final class UpdateUserMetaTest extends TestCase {

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
				'object_subtype' => 'user',
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
				'description'    => 'A test user meta key.',
			)
		);

		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		unregister_meta_key( 'user', 'abilities_catalog_test_key', 'user' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-users/update-meta' ) );
	}

	public function test_update_writes_registered_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'meta' => array( 'abilities_catalog_test_key' => 'Hello world' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->user_id, $result['id'] );
		$this->assertSame( 'Hello world', ( (array) $result['meta'] )['abilities_catalog_test_key'] );
		// Side-effect read-back via core.
		$this->assertSame( 'Hello world', get_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', true ) );
	}

	/**
	 * SECURITY: an unregistered/internal key (here wp_capabilities, which holds the
	 * user's role assignment) must be rejected as unknown, and nothing written.
	 */
	public function test_update_rejects_internal_unregistered_key(): void {
		$this->actingAs( 'administrator' );

		$before = get_metadata( 'user', $this->user_id, 'wp_capabilities', true );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'meta' => array( 'wp_capabilities' => array( 'administrator' => true ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		// Nothing was written: the internal capabilities meta is unchanged.
		$this->assertSame( $before, get_metadata( 'user', $this->user_id, 'wp_capabilities', true ) );
	}

	/**
	 * A `_`-prefixed internal key is likewise rejected, proving the gate is the
	 * registered show_in_rest set and not a prefix heuristic.
	 */
	public function test_update_rejects_underscore_prefixed_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'meta' => array( '_internal_key' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( '', get_metadata( 'user', $this->user_id, '_internal_key', true ) );
	}

	/**
	 * A missing user must surface a specific 404, not a permission collapse.
	 */
	public function test_update_missing_user_returns_invalid_id(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => 99999999,
				'meta' => array( 'abilities_catalog_test_key' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_meta_unchanged(): void {
		wp_set_current_user( 0 );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'original' );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'meta' => array( 'abilities_catalog_test_key' => 'changed' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'rest_user_invalid_id', $result->get_error_code() );
		// The meta survived unchanged.
		$this->assertSame( 'original', get_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', true ) );
	}

	/**
	 * A user without the object-level edit_user capability (a subscriber editing
	 * another user) is denied with a 403, not a 404, and nothing is written.
	 */
	public function test_user_without_edit_capability_is_denied(): void {
		$this->actingAs( 'subscriber' );
		update_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', 'original' );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'meta' => array( 'abilities_catalog_test_key' => 'changed' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 'original', get_metadata( 'user', $this->user_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_output_has_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-users/update-meta' )->execute(
			array(
				'id'   => $this->user_id,
				'meta' => array( 'abilities_catalog_test_key' => 'value' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'meta', 'edit_link' ), array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsObject( $result['meta'] );
		$this->assertIsString( $result['edit_link'] );
	}
}
