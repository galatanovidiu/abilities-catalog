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
	 * Capability gating: a subscriber lacks delete_post_tags, so the wrapped route
	 * denies the delete. The adapter's permission phase is guard-only and this
	 * ability has no require_permission guard, so check_permissions() would just
	 * return true; the denial runs at dispatch and surfaces through execute() as the
	 * route's REAL error (rest_cannot_delete / 403), not the generic collapse.
	 */
	public function test_subscriber_cannot_delete_tag(): void {
		$this->actingAs( 'subscriber' );

		$id     = self::factory()->tag->create( array( 'name' => 'Guarded' ) );
		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		// 403 because the user is logged in but lacks the capability.
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// The term still exists: the denial blocked the delete.
		$this->assertNotNull( get_term( $id, 'post_tag' ) );
	}

	/**
	 * Input validation: a non-positive id violates the closed input schema's
	 * `minimum: 1`, so the Abilities API rejects it before dispatch and a stray
	 * value cannot reach the route.
	 */
	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => -5 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_missing_tag_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// The route's own permission check runs at dispatch, so a non-existent id
		// reaches the route and surfaces its specific 404 instead of the generic
		// ability_invalid_permissions the Abilities API substitutes for a collapsed
		// permission denial.
		$result = wp_get_ability( 'og-terms/delete-tag' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
