<?php
/**
 * Integration tests for the og-terms/create-category ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the adapter-backed create-category write ability: registration, the
 * happy path, the parent/link output shape, capability gating, and duplicate-name
 * error specificity.
 */
final class CreateCategoryTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-terms/create-category' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-terms/create-category', $ability->get_name() );
	}

	/**
	 * Happy path: an administrator creates a top-level category and the stored
	 * term matches the returned id/name/slug.
	 */
	public function test_creates_category_and_returns_core_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-terms/create-category' )->execute(
			array(
				'name' => 'News',
				'slug' => 'news',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'News', $result['name'] );
		$this->assertSame( 'news', $result['slug'] );
		$this->assertSame( 0, $result['parent'] );
		$this->assertIsString( $result['link'] );
		$this->assertNotSame( '', $result['link'] );

		$term = get_term( $result['id'], 'category' );
		$this->assertSame( 'News', $term->name );
		$this->assertSame( 'news', $term->slug );
	}

	/**
	 * Output shape: a child category reports its parent term ID, letting the
	 * caller confirm the stored hierarchy without an extra read.
	 */
	public function test_returns_parent_for_child_category(): void {
		$this->actingAs( 'administrator' );

		$parent_id = self::factory()->category->create( array( 'name' => 'Parent' ) );

		$result = wp_get_ability( 'og-terms/create-category' )->execute(
			array(
				'name'   => 'Child',
				'parent' => $parent_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $parent_id, $result['parent'] );
		$this->assertSame(
			$parent_id,
			(int) get_term( $result['id'], 'category' )->parent,
			'The stored parent must match the requested parent.'
		);
	}

	/**
	 * Capability gating: a subscriber lacks `manage_categories`. The adapter's
	 * permission phase is guard-only and this ability has no require_permission
	 * guard, so the denial surfaces through execute() as the wrapped route's REAL
	 * error (`rest_cannot_create`, status 403), not the generic collapse.
	 */
	public function test_subscriber_cannot_create_category(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-terms/create-category' )->execute( array( 'name' => 'Denied' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	/**
	 * Error specificity: creating a category whose name already exists surfaces
	 * core's `term_exists` error code through the wrapped route.
	 */
	public function test_duplicate_name_surfaces_term_exists_error(): void {
		$this->actingAs( 'administrator' );

		self::factory()->category->create(
			array(
				'name' => 'Duplicate',
				'slug' => 'duplicate',
			)
		);

		$result = wp_get_ability( 'og-terms/create-category' )->execute(
			array(
				'name' => 'Duplicate',
				'slug' => 'duplicate',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_exists', $result->get_error_code() );
	}
}
