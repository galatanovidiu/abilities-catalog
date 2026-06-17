<?php
/**
 * Integration tests for the terms/update-term ability (generic, taxonomy-keyed).
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the generic update-term write ability, focused on the B7 follow-up:
 * an explicit empty `name`/`slug` must reach core (the validator), not be
 * dropped by a `'' !==` guard. Uses the core `category` taxonomy, which is
 * registered and `show_in_rest`.
 */
final class UpdateTermTest extends TestCase {

	/**
	 * Term under test (in the category taxonomy).
	 *
	 * @var int
	 */
	private int $term_id;

	public function set_up(): void {
		parent::set_up();

		$this->term_id = self::factory()->category->create(
			array(
				'name' => 'Original Term',
				'slug' => 'seeded-term-slug',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('terms/update-term');

		$this->assertNotNull($ability);
		$this->assertSame('terms/update-term', $ability->get_name());
	}

	/**
	 * B7 regression: an explicit empty `name` must reach core, which rejects it
	 * with `empty_term_name`. A `'' !==` guard would drop the value and silently
	 * no-op, hiding core's validation error.
	 */
	public function test_explicit_empty_name_surfaces_core_validation_error(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => $this->term_id,
				'name'     => '',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('empty_term_name', $result->get_error_code());
		$this->assertSame(
			'Original Term',
			get_term($this->term_id, 'category')->name,
			'The stored name must be unchanged when core rejects an empty name.'
		);
	}

	/**
	 * B7 regression: an explicit empty `slug` must reach core. The term was
	 * seeded with a slug (`seeded-term-slug`) that differs from its name. On an
	 * empty slug core regenerates the slug from the name (`Original Term` ->
	 * `original-term`), so the forwarded empty value is observable. A `'' !==`
	 * guard would drop the value and leave `seeded-term-slug` untouched, proving
	 * the empty slug never reached core.
	 */
	public function test_explicit_empty_slug_is_forwarded_to_core(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => $this->term_id,
				'slug'     => '',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('original-term', $result['slug']);
		$this->assertSame(
			'original-term',
			get_term($this->term_id, 'category')->slug,
			'An empty slug must reach core, which regenerates the slug from the name.'
		);
	}

	/**
	 * An omitted `name` means "leave unchanged".
	 */
	public function test_omitted_name_leaves_name_unchanged(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-term')->execute(
			array(
				'taxonomy'    => 'category',
				'id'          => $this->term_id,
				'description' => 'New description.',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('Original Term', $result['name']);
		$this->assertSame(
			'Original Term',
			get_term($this->term_id, 'category')->name,
			'An omitted name must leave the stored name unchanged.'
		);
	}

	/**
	 * Output completeness: a description edit must be confirmable from the
	 * structured result, not just from the wrapped route.
	 */
	public function test_output_returns_updated_description(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-term')->execute(
			array(
				'taxonomy'    => 'category',
				'id'          => $this->term_id,
				'description' => 'New description.',
			)
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('description', $result);
		$this->assertSame('New description.', $result['description']);
	}

	/**
	 * Output completeness: a hierarchy move (set parent) must be confirmable
	 * from the structured `parent` field.
	 */
	public function test_output_returns_updated_parent(): void {
		$this->actingAs('administrator');

		$parent_id = self::factory()->category->create(
			array( 'name' => 'Parent Term' )
		);

		$result = wp_get_ability('terms/update-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => $this->term_id,
				'parent'   => $parent_id,
			)
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('parent', $result);
		$this->assertSame($parent_id, $result['parent']);
	}

	public function test_missing_term_id_surfaces_route_404_not_generic(): void {
		$this->actingAs('administrator');

		// An admin holds the taxonomy's edit_terms cap (the coarse guard), so a
		// non-existent id reaches the route and surfaces its specific 404 instead of
		// the opaque ability_invalid_permissions the object-level pre-check produced.
		$result = wp_get_ability('terms/update-term')->execute(
			array(
				'taxonomy' => 'category',
				'id'       => 999999,
				'name'     => 'Renamed',
			)
		);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status'] ?? null);
	}
}
