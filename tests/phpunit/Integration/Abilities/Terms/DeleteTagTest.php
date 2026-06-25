<?php
/**
 * Integration tests for the og-terms/delete-tag ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises the delete-tag destructive write ability: registration, the happy
 * path, the additive previous_* output shape, capability gating, and the
 * non-positive-id input rejection.
 */
final class DeleteTagTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-terms/delete-tag' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-terms/delete-tag', $ability->get_name() );
	}

	/**
	 * Happy path: an administrator permanently deletes a tag and the term is
	 * gone afterward.
	 */
	public function test_deletes_tag_and_reports_deleted(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->tag->create(
			array(
				'name' => 'Doomed',
				'slug' => 'doomed',
			)
		);

		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertNull( get_term( $id, 'post_tag' ) );
	}

	/**
	 * Output shape: the additive previous_* fields report the term as it existed
	 * before deletion, so the caller knows what was removed.
	 */
	public function test_returns_previous_term_data(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->tag->create(
			array(
				'name' => 'Removable',
				'slug' => 'removable',
			)
		);

		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Removable', $result['previous_name'] );
		$this->assertSame( 'removable', $result['previous_slug'] );
		$this->assertIsString( $result['previous_link'] );
		$this->assertArrayHasKey( 'previous_count', $result );
		$this->assertArrayNotHasKey( 'previous_parent', $result );
	}

	/**
	 * Capability gating: a subscriber lacks delete_term, so the permission check
	 * denies execution.
	 */
	public function test_subscriber_cannot_delete_tag(): void {
		$this->actingAs( 'subscriber' );

		$id      = self::factory()->tag->create( array( 'name' => 'Guarded' ) );
		$ability = wp_get_ability( 'og-terms/delete-tag' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );
	}

	/**
	 * Input validation: a non-positive id is rejected by the schema before
	 * execute() runs, so absint() cannot silently retarget a real term.
	 */
	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => -5 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_missing_tag_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// An admin holds delete_post_tags (the coarse guard), so a non-existent id
		// reaches the route and surfaces its specific 404 instead of the opaque
		// ability_invalid_permissions the object-level pre-check produced.
		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
