<?php
/**
 * Integration tests for the terms/delete-term ability (generic, taxonomy-keyed).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the generic delete-term destructive write ability: registration, the
 * happy path on a hierarchical taxonomy, the additive previous_* output shape,
 * capability gating, missing-term and non-REST-taxonomy errors, and the
 * negative-ID rejection enforced by the input schema minimum.
 */
final class DeleteTermTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'terms/delete-term' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'terms/delete-term', $ability->get_name() );
	}

	/**
	 * Happy path: an administrator permanently deletes a term and the additive
	 * previous_* fields report the term as it existed before deletion.
	 */
	public function test_deletes_term_and_reports_previous_data(): void {
		$this->actingAs( 'administrator' );

		$parent_id = self::factory()->category->create(
			array(
				'name' => 'Parent',
				'slug' => 'parent',
			)
		);
		$id        = self::factory()->category->create(
			array(
				'name'   => 'Doomed',
				'slug'   => 'doomed',
				'parent' => $parent_id,
			)
		);

		$result = wp_get_ability( 'terms/delete-term' )->execute(
			array(
				'taxonomy' => 'category',
				'id'       => $id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'category', $result['previous_taxonomy'] );
		$this->assertSame( 'Doomed', $result['previous_name'] );
		$this->assertSame( 'doomed', $result['previous_slug'] );
		$this->assertSame( $parent_id, $result['previous_parent'] );
		$this->assertIsString( $result['previous_link'] );
		$this->assertArrayHasKey( 'previous_count', $result );
		$this->assertNull( get_term( $id, 'category' ) );
	}

	/**
	 * Capability gating: a subscriber lacks delete_term, so the permission check
	 * denies execution.
	 */
	public function test_subscriber_cannot_delete_term(): void {
		$this->actingAs( 'subscriber' );

		$id      = self::factory()->category->create( array( 'name' => 'Guarded' ) );
		$ability = wp_get_ability( 'terms/delete-term' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'taxonomy' => 'category',
					'id'       => $id,
				)
			)
		);
	}

	/**
	 * Schema guard: a negative ID is rejected by validate_input() before any
	 * callback runs, so absint() can never retarget it to a positive term.
	 */
	public function test_negative_id_is_rejected_before_deletion(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->category->create( array( 'name' => 'Safe' ) );

		$result = wp_get_ability( 'terms/delete-term' )->execute(
			array(
				'taxonomy' => 'category',
				'id'       => -$id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotNull(
			get_term( $id, 'category' ),
			'The same-magnitude positive term must remain after a negative-ID call.'
		);
	}

	/**
	 * A non-REST taxonomy cannot be deleted: the permission check guards on
	 * show_in_rest, so the term survives.
	 */
	public function test_non_rest_taxonomy_is_rejected(): void {
		$this->actingAs( 'administrator' );

		register_taxonomy(
			'secret_tax',
			'post',
			array(
				'show_in_rest' => false,
			)
		);
		$id      = self::factory()->term->create(
			array(
				'taxonomy' => 'secret_tax',
				'name'     => 'Hidden',
			)
		);
		$ability = wp_get_ability( 'terms/delete-term' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'taxonomy' => 'secret_tax',
					'id'       => $id,
				)
			)
		);

		$result = $ability->execute(
			array(
				'taxonomy' => 'secret_tax',
				'id'       => $id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotNull(
			get_term( $id, 'secret_tax' ),
			'A non-REST taxonomy term must survive the rejected delete.'
		);

		unregister_taxonomy( 'secret_tax' );
	}
}
