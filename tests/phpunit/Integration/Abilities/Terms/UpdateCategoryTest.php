<?php
/**
 * Integration tests for the terms/update-category ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the update-category write ability, focused on the B7 follow-up:
 * an explicit empty `name`/`slug` is the caller's "blank this field" intent and
 * must reach core (the validator), not be dropped by a `'' !==` guard.
 */
final class UpdateCategoryTest extends TestCase {

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
				'name' => 'Original Name',
				'slug' => 'original-slug',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('terms/update-category');

		$this->assertNotNull($ability);
		$this->assertSame('terms/update-category', $ability->get_name());
	}

	/**
	 * B7 regression: an explicit empty `name` must reach core, which rejects it
	 * with `empty_term_name` (HTTP 500 — the WP_Error from wp_update_term carries
	 * no status). A `'' !==` guard would drop the value and silently no-op,
	 * hiding core's validation error.
	 */
	public function test_explicit_empty_name_surfaces_core_validation_error(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-category')->execute(
			array(
				'id'   => $this->term_id,
				'name' => '',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('empty_term_name', $result->get_error_code());
		$this->assertSame(
			'Original Name',
			get_term($this->term_id, 'category')->name,
			'The stored name must be unchanged when core rejects an empty name.'
		);
	}

	/**
	 * B7 regression: an explicit empty `slug` must reach core. The term was
	 * seeded with a slug (`original-slug`) that differs from its name. On an
	 * empty slug core regenerates the slug from the name (`Original Name` ->
	 * `original-name`), so the forwarded empty value is observable. A `'' !==`
	 * guard would drop the value and leave the seeded `original-slug` untouched,
	 * proving the empty slug never reached core.
	 */
	public function test_explicit_empty_slug_is_forwarded_to_core(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-category')->execute(
			array(
				'id'   => $this->term_id,
				'slug' => '',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('original-name', $result['slug']);
		$this->assertSame(
			'original-name',
			get_term($this->term_id, 'category')->slug,
			'An empty slug must reach core, which regenerates the slug from the name.'
		);
	}

	/**
	 * The output must echo the updated `description` and `parent` so a caller can
	 * confirm a description edit or a category move from the structured result.
	 */
	public function test_output_includes_description_and_parent(): void {
		$this->actingAs('administrator');

		$parent_id = self::factory()->category->create(
			array( 'name' => 'Parent Category' )
		);

		$result = wp_get_ability('terms/update-category')->execute(
			array(
				'id'          => $this->term_id,
				'description' => 'Moved under a parent.',
				'parent'      => $parent_id,
			)
		);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('description', $result);
		$this->assertArrayHasKey('parent', $result);
		$this->assertSame('Moved under a parent.', $result['description']);
		$this->assertSame($parent_id, $result['parent']);
	}

	/**
	 * An omitted `name` means "leave unchanged": updating only the description
	 * must not touch the name.
	 */
	public function test_omitted_name_leaves_name_unchanged(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-category')->execute(
			array(
				'id'          => $this->term_id,
				'description' => 'New description.',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('Original Name', $result['name']);
		$this->assertSame(
			'Original Name',
			get_term($this->term_id, 'category')->name,
			'An omitted name must leave the stored name unchanged.'
		);
	}
}
