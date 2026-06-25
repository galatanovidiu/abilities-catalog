<?php
/**
 * Integration tests for the og-terms/create-term ability (generic, taxonomy-keyed).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Exercises the generic create-term write ability, focused on the additive
 * `parent` output field so callers can verify the created hierarchy without an
 * extra read. Uses the core `category` taxonomy, which is hierarchical and
 * `show_in_rest`.
 */
final class CreateTermTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-terms/create-term');

		$this->assertNotNull($ability);
		$this->assertSame('og-terms/create-term', $ability->get_name());
	}

	/**
	 * A top-level term reports `parent` as 0.
	 */
	public function test_returns_zero_parent_for_top_level_term(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/create-term')->execute(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
				'slug'     => 'news',
			)
		);

		$this->assertIsArray($result);
		$this->assertGreaterThan(0, $result['id']);
		$this->assertSame(0, $result['parent']);
	}

	/**
	 * A child term reports its parent term ID, letting the caller confirm the
	 * stored hierarchy without an extra read.
	 */
	public function test_returns_parent_for_child_term(): void {
		$this->actingAs('administrator');

		$parent_id = self::factory()->category->create(array('name' => 'Parent'));

		$result = wp_get_ability('og-terms/create-term')->execute(
			array(
				'taxonomy' => 'category',
				'name'     => 'Child',
				'parent'   => $parent_id,
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
}
