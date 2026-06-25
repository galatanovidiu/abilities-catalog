<?php
/**
 * Integration tests for the og-terms/get-category ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the get-category read ability: registration, happy-path shape, and
 * the missing-term error path (a positive-but-nonexistent ID must surface core's
 * specific `rest_term_invalid` 404, not collapse into a generic permission error).
 */
final class GetCategoryTest extends TestCase {

	/**
	 * Category under test.
	 *
	 * @var int
	 */
	private int $term_id;

	public function set_up(): void {
		parent::set_up();

		$this->term_id = self::factory()->category->create(
			array(
				'name' => 'News',
				'slug' => 'news',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('og-terms/get-category');

		$this->assertNotNull($ability);
		$this->assertSame('og-terms/get-category', $ability->get_name());
	}

	/**
	 * Happy path: an authenticated user reading an existing category gets the
	 * flat field set with the expected `id`/`name`/`slug`/`taxonomy`.
	 */
	public function test_returns_flat_category_shape(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-category')->execute(
			array(
				'id' => $this->term_id,
			)
		);

		$this->assertIsArray($result);
		$this->assertSame($this->term_id, $result['id']);
		$this->assertSame('News', $result['name']);
		$this->assertSame('news', $result['slug']);
		$this->assertSame('category', $result['taxonomy']);
	}

	/**
	 * The output is restricted to the declared flat field set; no nested or
	 * extra REST fields (e.g. `_links`, `meta`) leak through.
	 */
	public function test_output_shape_is_limited_to_declared_fields(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-category')->execute(
			array(
				'id' => $this->term_id,
			)
		);

		$this->assertIsArray($result);
		$this->assertSame(
			array( 'id', 'name', 'slug', 'description', 'parent', 'count', 'taxonomy', 'link' ),
			array_keys($result),
			'The result must expose only the declared flat fields.'
		);
	}

	/**
	 * A positive-but-nonexistent ID must surface core's specific
	 * `rest_term_invalid` 404, not collapse into a generic permission error.
	 */
	public function test_missing_term_surfaces_rest_term_invalid_404(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-category')->execute(
			array(
				'id' => 999999,
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_term_invalid', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status'] ?? null);
	}
}
