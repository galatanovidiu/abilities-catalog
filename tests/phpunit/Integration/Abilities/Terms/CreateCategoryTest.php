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
 * Exercises the create-category write ability: registration, the happy path,
 * the parent/link output shape, capability gating, and duplicate-name error
 * specificity.
 */
final class CreateCategoryTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-terms/create-category');

		$this->assertNotNull($ability);
		$this->assertSame('og-terms/create-category', $ability->get_name());
	}

	/**
	 * Happy path: an administrator creates a top-level category and the stored
	 * term matches the returned id/name/slug.
	 */
	public function test_creates_category_and_returns_core_shape(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/create-category')->execute(
			array(
				'name' => 'News',
				'slug' => 'news',
			)
		);

		$this->assertIsArray($result);
		$this->assertGreaterThan(0, $result['id']);
		$this->assertSame('News', $result['name']);
		$this->assertSame('news', $result['slug']);
		$this->assertSame(0, $result['parent']);
		$this->assertIsString($result['link']);
		$this->assertNotSame('', $result['link']);

		$term = get_term($result['id'], 'category');
		$this->assertSame('News', $term->name);
		$this->assertSame('news', $term->slug);
	}

	/**
	 * Output shape: a child category reports its parent term ID, letting the
	 * caller confirm the stored hierarchy without an extra read.
	 */
	public function test_returns_parent_for_child_category(): void {
		$this->actingAs('administrator');

		$parent_id = self::factory()->category->create(array('name' => 'Parent'));

		$result = wp_get_ability('og-terms/create-category')->execute(
			array(
				'name'   => 'Child',
				'parent' => $parent_id,
			)
		);

		$this->assertIsArray($result);
		$this->assertSame($parent_id, $result['parent']);
		$this->assertSame(
			$parent_id,
			(int) get_term($result['id'], 'category')->parent,
			'The stored parent must match the requested parent.'
		);
	}

	/**
	 * Capability gating: a subscriber lacks `manage_categories`, so the ability's
	 * permission check denies execution.
	 */
	public function test_subscriber_cannot_create_category(): void {
		$this->actingAs('subscriber');

		$ability = wp_get_ability('og-terms/create-category');

		$this->assertFalse($ability->check_permissions(array('name' => 'Denied')));
	}

	/**
	 * Error specificity: creating a category whose name already exists surfaces
	 * core's `term_exists` error code through the shared RestError helper.
	 */
	public function test_duplicate_name_surfaces_term_exists_error(): void {
		$this->actingAs('administrator');

		self::factory()->category->create(
			array(
				'name' => 'Duplicate',
				'slug' => 'duplicate',
			)
		);

		$result = wp_get_ability('og-terms/create-category')->execute(
			array(
				'name' => 'Duplicate',
				'slug' => 'duplicate',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('term_exists', $result->get_error_code());
	}
}
