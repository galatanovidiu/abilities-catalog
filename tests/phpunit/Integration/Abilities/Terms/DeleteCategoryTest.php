<?php
/**
 * Integration tests for the terms/delete-category ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises the delete-category destructive write ability: registration, the
 * happy path, the additive previous_* output shape, capability gating, and the
 * default-category restriction.
 */
final class DeleteCategoryTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'terms/delete-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'terms/delete-category', $ability->get_name() );
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

		$result = wp_get_ability( 'terms/delete-category' )->execute( array( 'id' => $id ) );

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

		$result = wp_get_ability( 'terms/delete-category' )->execute( array( 'id' => $id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Removable', $result['previous_name'] );
		$this->assertSame( 'removable', $result['previous_slug'] );
		$this->assertSame( $parent_id, $result['previous_parent'] );
		$this->assertIsString( $result['previous_link'] );
		$this->assertArrayHasKey( 'previous_count', $result );
	}

	/**
	 * Capability gating: a subscriber lacks delete_term, so the permission check
	 * denies execution.
	 */
	public function test_subscriber_cannot_delete_category(): void {
		$this->actingAs( 'subscriber' );

		$id      = self::factory()->category->create( array( 'name' => 'Guarded' ) );
		$ability = wp_get_ability( 'terms/delete-category' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $id ) ) );
	}

	/**
	 * Restriction: even an administrator cannot delete the site's default
	 * category; the delete_term meta cap maps it to do_not_allow.
	 */
	public function test_default_category_cannot_be_deleted(): void {
		$this->actingAs( 'administrator' );

		$default_id = (int) get_option( 'default_category' );
		$ability    = wp_get_ability( 'terms/delete-category' );

		$this->assertFalse( $ability->check_permissions( array( 'id' => $default_id ) ) );
	}
}
