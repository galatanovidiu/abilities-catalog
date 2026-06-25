<?php
/**
 * Integration tests for the og-terms/delete-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-terms/delete-meta end-to-end against a registered show_in_rest term
 * meta key: the happy-path removal, the registered-key security gate (an
 * unregistered/internal key is rejected and nothing is deleted), the specific
 * missing-term 404, the per-key delete capability guard, the logged-out denial,
 * and the exact output shape.
 */
final class DeleteTermMetaTest extends TestCase {

	/**
	 * Term under test (a category).
	 *
	 * @var int
	 */
	private int $term_id;

	public function set_up(): void {
		parent::set_up();

		register_term_meta(
			'category',
			'abilities_catalog_test_key',
			array(
				'show_in_rest' => true,
				'single'       => true,
				'type'         => 'string',
				'description'  => 'A test term meta key.',
			)
		);

		// A registered key whose auth_callback allows edit but denies delete, so
		// the per-key delete_term_meta guard can be exercised independently.
		register_term_meta(
			'category',
			'guarded_meta',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => static function ( $allowed, $meta_key, $object_id, $user_id, $cap ) {
					return 'delete_term_meta' !== $cap; // Allow edit, deny delete.
				},
			)
		);

		$this->term_id = self::factory()->category->create( array( 'name' => 'Meta Holder' ) );
		add_term_meta( $this->term_id, 'abilities_catalog_test_key', 'stored value' );
	}

	public function tear_down(): void {
		unregister_term_meta( 'category', 'abilities_catalog_test_key' );
		unregister_term_meta( 'category', 'guarded_meta' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-terms/delete-meta' ) );
	}

	public function test_deletes_registered_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/delete-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'abilities_catalog_test_key' ), $result['deleted'] );
		$this->assertSame( $this->term_id, $result['id'] );
		// Read back: the meta is gone.
		$this->assertSame( '', get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true ) );
		$this->assertSame( array(), get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', false ) );
	}

	public function test_unknown_key_is_rejected_and_nothing_deleted(): void {
		$this->actingAs( 'administrator' );

		// wp_capabilities is internal user meta that must never be reachable; for a
		// term, an unregistered/internal key like a _-prefixed one is rejected too.
		$result = wp_get_ability( 'og-terms/delete-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'keys' => array( 'abilities_catalog_test_key', '_internal_secret' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		// Validation fails before any delete, so the registered key survives intact.
		$this->assertSame( 'stored value', get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_missing_term_returns_invalid_id_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/delete-meta' )->execute(
			array(
				'id'   => 99999999,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_user_without_delete_term_meta_is_denied(): void {
		$this->actingAs( 'administrator' );
		add_term_meta( $this->term_id, 'guarded_meta', 'keep me' );

		// An administrator can edit the term, but the auth_callback denies the
		// per-key delete_term_meta cap.
		$result = wp_get_ability( 'og-terms/delete-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'keys' => array( 'guarded_meta' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_delete_meta', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_term_invalid', $result->get_error_code() );
		// The value survives the rejected delete.
		$this->assertSame( 'keep me', get_metadata( 'term', $this->term_id, 'guarded_meta', true ) );
	}

	public function test_logged_out_user_is_denied_and_value_survives(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-terms/delete-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// A logged-out user cannot edit the term, so the object-level guard in
		// execute() returns a specific authorization error, not the generic collapse.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		// The meta survives the denied delete.
		$this->assertSame( 'stored value', get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_output_has_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/delete-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'keys' => array( 'abilities_catalog_test_key' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'deleted', 'edit_link' ), array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsArray( $result['deleted'] );
		$this->assertIsString( $result['edit_link'] );
	}
}
