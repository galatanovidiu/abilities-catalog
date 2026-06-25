<?php
/**
 * Integration tests for the og-terms/get-term ability (generic, taxonomy-keyed).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the generic get-term read ability: registration, happy-path flat
 * shape, the negative-ID path (must surface core's specific `rest_term_invalid`
 * rather than reading a different real term), the two distinct taxonomy error
 * branches, and edit-context capability gating.
 */
final class GetTermTest extends TestCase {

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
		$ability = wp_get_ability('og-terms/get-term');

		$this->assertNotNull($ability);
		$this->assertSame('og-terms/get-term', $ability->get_name());
	}

	/**
	 * Happy path: a view read of an existing term returns the flat field set.
	 */
	public function test_returns_flat_term_shape(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => $this->term_id,
			)
		);

		$this->assertIsArray($result);
		$this->assertSame($this->term_id, $result['id']);
		$this->assertSame('News', $result['name']);
		$this->assertSame('news', $result['slug']);
		$this->assertSame('category', $result['taxonomy']);
		$this->assertArrayHasKey('link', $result);
	}

	/**
	 * A negative ID must not be coerced into a different real term via absint().
	 * With `minimum => 1` on the input schema the ability rejects it as invalid
	 * input; the guarantee under test is that it never returns the data of term
	 * abs(-id).
	 */
	public function test_negative_id_is_rejected_not_coerced_to_other_term(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => -$this->term_id,
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame(
			'ability_invalid_input',
			$result->get_error_code(),
			'A negative id must be rejected by input validation, not coerced via absint.'
		);
	}

	/**
	 * A positive-but-nonexistent ID flows to the route and surfaces core's
	 * specific `rest_term_invalid` error rather than a generic permission failure.
	 */
	public function test_missing_term_surfaces_rest_term_invalid(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => 999999,
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_term_invalid', $result->get_error_code());
	}

	/**
	 * An unknown taxonomy slug returns the distinct `rest_taxonomy_invalid` code.
	 */
	public function test_unknown_taxonomy_returns_invalid_code(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-term')->execute(
			array(
				'taxonomy' => 'no_such_taxonomy',
				'id'       => $this->term_id,
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
			'gt_private_tax',
			'post',
			array(
				'show_in_rest' => false,
			)
		);

		$this->actingAs('administrator');

		$result = wp_get_ability('og-terms/get-term')->execute(
			array(
				'taxonomy' => 'gt_private_tax',
				'id'       => $this->term_id,
			)
		);

		unregister_taxonomy('gt_private_tax');

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('rest_taxonomy_not_rest', $result->get_error_code());
	}

	/**
	 * Edit context requires the taxonomy's manage_terms capability; a user
	 * without it is denied by the permission callback.
	 */
	public function test_edit_context_denied_without_capability(): void {
		$this->actingAs('subscriber');

		$allowed = wp_get_ability('og-terms/get-term')->check_permissions(
			array(
				'taxonomy' => 'category',
				'id'       => $this->term_id,
				'context'  => 'edit',
			)
		);

		$this->assertNotTrue($allowed);
	}
}
