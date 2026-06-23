<?php
/**
 * Integration tests for the terms/get-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises terms/get-meta end-to-end against a registered show_in_rest meta
 * key on the category taxonomy: registration, the happy-path read, the
 * security gate (unregistered/internal keys are never returned), the
 * missing-object 404 (not collapsed to permission), and capability gating.
 */
final class GetTermMetaTest extends TestCase {

	/**
	 * Category under test.
	 *
	 * @var int
	 */
	private int $term_id;

	public function set_up(): void {
		parent::set_up();

		register_meta(
			'term',
			'abilities_catalog_test_key',
			array(
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
				'object_subtype' => 'category',
			)
		);

		$term          = wp_insert_term( 'W5 meta term ' . uniqid(), 'category' );
		$this->term_id = (int) $term['term_id'];
	}

	public function tear_down(): void {
		unregister_meta_key( 'term', 'abilities_catalog_test_key', 'category' );
		wp_delete_term( $this->term_id, 'category' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'terms/get-meta' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'terms/get-meta', $ability->get_name() );
	}

	/**
	 * Happy path: a registered key's stored value comes back in the meta map.
	 */
	public function test_returns_registered_meta_value(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', 'hello term' );

		$result = wp_get_ability( 'terms/get-meta' )->execute( array( 'id' => $this->term_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->term_id, $result['id'] );
		$this->assertIsObject( $result['meta'] );
		$meta = (array) $result['meta'];
		$this->assertArrayHasKey( 'abilities_catalog_test_key', $meta );
		$this->assertSame( 'hello term', $meta['abilities_catalog_test_key'] );
	}

	/**
	 * Output shape is exactly { id, meta }.
	 */
	public function test_output_shape_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/get-meta' )->execute( array( 'id' => $this->term_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'meta' ), array_keys( $result ) );
	}

	/**
	 * Security: an unregistered/internal key is simply absent from the result.
	 * The read filters to registered show_in_rest keys, so a `_`-prefixed
	 * internal key (or any unknown key) can never be surfaced.
	 */
	public function test_unregistered_key_is_silently_absent(): void {
		$this->actingAs( 'administrator' );
		update_metadata( 'term', $this->term_id, '_internal_secret', 'do not leak' );

		$result = wp_get_ability( 'terms/get-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'keys' => array( 'abilities_catalog_test_key', '_internal_secret', 'does_not_exist' ),
			)
		);

		$this->assertIsArray( $result );
		$meta = (array) $result['meta'];
		$this->assertArrayNotHasKey( '_internal_secret', $meta );
		$this->assertArrayNotHasKey( 'does_not_exist', $meta );
		$this->assertArrayHasKey( 'abilities_catalog_test_key', $meta );
	}

	/**
	 * A missing term surfaces the specific rest_term_invalid 404, never the
	 * generic permission collapse.
	 */
	public function test_missing_term_surfaces_rest_term_invalid_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'terms/get-meta' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A logged-out caller cannot edit the term, so the object-level guard returns
	 * a specific authorization error rather than reading the meta.
	 */
	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'terms/get-meta' )->execute( array( 'id' => $this->term_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * A user lacking edit_term on the term gets a 403, asserted NOT the 404 —
	 * the existence check passed, only the capability failed.
	 */
	public function test_user_without_edit_term_gets_403_not_404(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'terms/get-meta' )->execute( array( 'id' => $this->term_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'rest_term_invalid', $result->get_error_code() );
	}
}
