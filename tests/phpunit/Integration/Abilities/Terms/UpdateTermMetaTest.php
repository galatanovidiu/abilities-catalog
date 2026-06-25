<?php
/**
 * Integration tests for the og-terms/update-meta ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises og-terms/update-meta against a registered show_in_rest meta key, plus the
 * registered-key security gate (internal/unregistered keys are rejected) and the
 * object/permission guards.
 */
final class UpdateTermMetaTest extends TestCase {

	/**
	 * Term under test (in the category taxonomy).
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
				'object_subtype' => 'category',
				'show_in_rest'   => true,
				'single'         => true,
				'type'           => 'string',
				'description'    => 'A test term meta key.',
			)
		);

		$this->term_id = self::factory()->category->create(
			array( 'name' => 'Meta Term' )
		);
	}

	public function tear_down(): void {
		unregister_meta_key( 'term', 'abilities_catalog_test_key', 'category' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-terms/update-meta' ) );
	}

	public function test_update_writes_registered_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array( 'abilities_catalog_test_key' => 'Hello world' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->term_id, $result['id'] );
		$this->assertSame( 'Hello world', ( (array) $result['meta'] )['abilities_catalog_test_key'] );
		// Side-effect read-back via the generic metadata function.
		$this->assertSame(
			'Hello world',
			get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true )
		);
	}

	public function test_output_has_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array( 'abilities_catalog_test_key' => 'shape' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'meta', 'edit_link' ), array_keys( $result ) );
		$this->assertIsInt( $result['id'] );
		$this->assertIsObject( $result['meta'] );
		$this->assertIsString( $result['edit_link'] );
	}

	/**
	 * SECURITY: an unregistered/internal key must be rejected and nothing written.
	 * This is the whole point of the cluster — arbitrary/internal term meta must
	 * never be writable.
	 */
	public function test_update_rejects_unregistered_key_and_writes_nothing(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array( '_internal' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		// Nothing was written.
		$this->assertSame( '', get_metadata( 'term', $this->term_id, '_internal', true ) );
	}

	/**
	 * A mix of a valid key and an unregistered key writes nothing: validation runs
	 * over every key before any write.
	 */
	public function test_update_unknown_key_in_batch_blocks_valid_key(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array(
					'abilities_catalog_test_key' => 'should not persist',
					'_internal'                  => 'x',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_unknown_key', $result->get_error_code() );
		// The registered key must NOT have been written despite appearing first.
		$this->assertSame( '', get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true ) );
	}

	public function test_empty_meta_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_meta_empty', $result->get_error_code() );
		$this->assertSame( 400, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_missing_term_returns_404_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => 99999999,
				'meta' => array( 'abilities_catalog_test_key' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_meta_unchanged(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array( 'abilities_catalog_test_key' => 'never written' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		// A logged-out user cannot edit the term, so the object-level guard returns a
		// specific authorization error rather than the generic permission collapse.
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		// Nothing was written.
		$this->assertSame( '', get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true ) );
	}

	/**
	 * A user lacking the term-edit capability is denied at the object-level guard
	 * (403), before any per-key validation.
	 */
	public function test_user_without_edit_term_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-terms/update-meta' )->execute(
			array(
				'id'   => $this->term_id,
				'meta' => array( 'abilities_catalog_test_key' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		// Not collapsed to a 404; the term exists.
		$this->assertSame( '', get_metadata( 'term', $this->term_id, 'abilities_catalog_test_key', true ) );
	}
}
