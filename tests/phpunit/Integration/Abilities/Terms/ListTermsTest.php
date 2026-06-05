<?php
/**
 * Integration tests for the terms/list-terms ability (generic, taxonomy-keyed).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the generic list-terms read ability: registration, the totals shape,
 * the two distinct taxonomy error branches, and the hierarchical `parent` filter.
 */
final class ListTermsTest extends TestCase {

	/**
	 * Top-level category.
	 *
	 * @var int
	 */
	private int $parent_id;

	/**
	 * Child category of $parent_id.
	 *
	 * @var int
	 */
	private int $child_id;

	public function set_up(): void {
		parent::set_up();

		$this->parent_id = self::factory()->category->create(
			array(
				'name' => 'News',
				'slug' => 'news',
			)
		);

		$this->child_id = self::factory()->category->create(
			array(
				'name'   => 'World',
				'slug'   => 'world',
				'parent' => $this->parent_id,
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('terms/list-terms');

		$this->assertNotNull($ability);
		$this->assertSame('terms/list-terms', $ability->get_name());
	}

	/**
	 * Happy path: returns items plus the pagination totals.
	 */
	public function test_returns_items_with_totals(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/list-terms')->execute(
			array(
				'taxonomy' => 'category',
			)
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('items', $result);
		$this->assertArrayHasKey('total', $result);
		$this->assertArrayHasKey('total_pages', $result);
		$this->assertIsInt($result['total']);
		$this->assertIsInt($result['total_pages']);
	}

	/**
	 * The hierarchical `parent` filter is forwarded to the route: passing the
	 * parent term ID returns only its children.
	 */
	public function test_parent_filter_limits_to_children(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/list-terms')->execute(
			array(
				'taxonomy' => 'category',
				'parent'   => $this->parent_id,
			)
		);

		$this->assertIsArray($result);
		$ids = wp_list_pluck($result['items'], 'id');
		$this->assertContains($this->child_id, $ids);
		$this->assertNotContains($this->parent_id, $ids);
	}

	/**
	 * An unknown taxonomy slug returns the distinct `rest_taxonomy_invalid` code.
	 */
	public function test_unknown_taxonomy_returns_invalid_code(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/list-terms')->execute(
			array(
				'taxonomy' => 'no_such_taxonomy',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_taxonomy_invalid', $result->get_error_code());
	}

	/**
	 * A registered taxonomy that is not exposed to REST returns the distinct
	 * `rest_taxonomy_not_rest` code, kept separate from the unknown-slug case.
	 */
	public function test_non_rest_taxonomy_returns_not_rest_code(): void {
		register_taxonomy(
			'lt_private_tax',
			'post',
			array(
				'show_in_rest' => false,
			)
		);

		$this->actingAs('administrator');

		$result = wp_get_ability('terms/list-terms')->execute(
			array(
				'taxonomy' => 'lt_private_tax',
			)
		);

		unregister_taxonomy('lt_private_tax');

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_taxonomy_not_rest', $result->get_error_code());
	}
}
