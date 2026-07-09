<?php
/**
 * Integration tests for the og-terms/delete-category ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the delete-category destructive write ability end-to-end: registration,
 * the happy path, the additive previous_* output shape, and capability gating. The
 * ability is adapter-backed, so permission delegates to the wrapped DELETE route and
 * denials surface through execute() as the route's REAL error, not the generic
 * ability_invalid_permissions collapse.
 */
final class DeleteCategoryTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-terms/delete-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-terms/delete-category', $ability->get_name() );
	}

	/**
	 * Happy path: an administrator permanently deletes a category and the term
	 * is gone afterward.
	 */
	public function test_deletes_category_and_reports_deleted(): void {
		$this->actingAs( 'administrator' );

		$id = self::factory()->category->create(
			array(
				'name' => 'Doomed',
				'slug' => 'doomed',
			)
		);

		$result = wp_get_ability( 'og-terms/delete-category' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $id, $result['id'] );
		$this->assertNull( get_term( $id, 'category' ) );
	}

	/**
	 * Output shape: the additive previous_* fields report the term as it existed
	 * before deletion, so the caller knows what was removed.
	 */
	public function test_returns_previous_term_data(): void {
		$this->actingAs( 'administrator' );

		$parent_id = self::factory()->category->create(
			array(
				'name' => 'Parent',
				'slug' => 'parent',
			)
		);
		$id        = self::factory()->category->create(
			array(
				'name'   => 'Removable',
				'slug'   => 'removable',
				'parent' => $parent_id,
			)
		);

		$result = wp_get_ability( 'og-terms/delete-category' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Removable', $result['previous_name'] );
		$this->assertSame( 'removable', $result['previous_slug'] );
		$this->assertSame( $parent_id, $result['previous_parent'] );
		$this->assertIsString( $result['previous_link'] );
		$this->assertArrayHasKey( 'previous_count', $result );
	}

	/**
	 * Capability gating: a subscriber lacks delete_categories, so the route denies
	 * the delete. The adapter's permission phase is guard-only and this ability sets
	 * no require_permission floor, so check_permissions() returns true; the denial
	 * lives on the execute() path, where the route's REAL error surfaces (not the
	 * generic ability_invalid_permissions collapse) and the term survives.
	 */
	public function test_subscriber_cannot_delete_category(): void {
		$this->actingAs( 'subscriber' );

		$id = self::factory()->category->create( array( 'name' => 'Guarded' ) );

		$result = wp_get_ability( 'og-terms/delete-category' )->execute( array( 'id' => $id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$this->assertNotNull( get_term( $id, 'category' ) );
	}

	/**
	 * Restriction: even an administrator cannot delete the site's default category.
	 *
	 * Permission delegates to the route, which refuses to delete the default
	 * category, so execute() returns the route's error and the term survives. The
	 * specific route error reaches the caller, not a generic permission denial.
	 */
	public function test_default_category_cannot_be_deleted(): void {
		$this->actingAs( 'administrator' );

		$default_id = (int) get_option( 'default_category' );

		$result = wp_get_ability( 'og-terms/delete-category' )->execute( array( 'id' => $default_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertNotNull( get_term( $default_id, 'category' ) );
	}

	public function test_missing_category_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// An admin holds delete_categories, so a non-existent id reaches the route and
		// surfaces its specific 404 through execute(), not the opaque
		// ability_invalid_permissions an object-level pre-check would produce.
		$result = wp_get_ability( 'og-terms/delete-category' )->execute( array( 'id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}
}
