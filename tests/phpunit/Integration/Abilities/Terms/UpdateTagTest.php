<?php
/**
 * Integration tests for the terms/update-tag ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the update-tag write ability, focused on the B7 follow-up: an
 * explicit empty `name`/`slug` must reach core (the validator), not be dropped
 * by a `'' !==` guard.
 */
final class UpdateTagTest extends TestCase {

	/**
	 * Tag under test.
	 *
	 * @var int
	 */
	private int $term_id;

	public function set_up(): void {
		parent::set_up();

		$this->term_id = self::factory()->tag->create(
			array(
				'name' => 'Original Tag',
				'slug' => 'seeded-tag-slug',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('terms/update-tag');

		$this->assertNotNull($ability);
		$this->assertSame('terms/update-tag', $ability->get_name());
	}

	/**
	 * B7 regression: an explicit empty `name` must reach core, which rejects it
	 * with `empty_term_name`. A `'' !==` guard would drop the value and silently
	 * no-op, hiding core's validation error.
	 */
	public function test_explicit_empty_name_surfaces_core_validation_error(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-tag')->execute(
			array(
				'id'   => $this->term_id,
				'name' => '',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('empty_term_name', $result->get_error_code());
		$this->assertSame(
			'Original Tag',
			get_term($this->term_id, 'post_tag')->name,
			'The stored name must be unchanged when core rejects an empty name.'
		);
	}

	/**
	 * B7 regression: an explicit empty `slug` must reach core. The term was
	 * seeded with a slug (`seeded-tag-slug`) that differs from its name. On an
	 * empty slug core regenerates the slug from the name (`Original Tag` ->
	 * `original-tag`), so the forwarded empty value is observable. A `'' !==`
	 * guard would drop the value and leave `seeded-tag-slug` untouched, proving
	 * the empty slug never reached core.
	 */
	public function test_explicit_empty_slug_is_forwarded_to_core(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-tag')->execute(
			array(
				'id'   => $this->term_id,
				'slug' => '',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('original-tag', $result['slug']);
		$this->assertSame(
			'original-tag',
			get_term($this->term_id, 'post_tag')->slug,
			'An empty slug must reach core, which regenerates the slug from the name.'
		);
	}

	/**
	 * A description-only update must be verifiable from the tool output: the
	 * result returns the updated `description` and the public archive `link`.
	 */
	public function test_description_update_is_returned_in_output(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-tag')->execute(
			array(
				'id'          => $this->term_id,
				'description' => 'A fresh description.',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('A fresh description.', $result['description']);
		$this->assertNotEmpty($result['link']);
		$this->assertSame(
			'A fresh description.',
			get_term($this->term_id, 'post_tag')->description,
			'The stored description must reflect the update.'
		);
	}

	/**
	 * An omitted `name` means "leave unchanged".
	 */
	public function test_omitted_name_leaves_name_unchanged(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/update-tag')->execute(
			array(
				'id'          => $this->term_id,
				'description' => 'New description.',
			)
		);

		$this->assertIsArray($result);
		$this->assertSame('Original Tag', $result['name']);
		$this->assertSame(
			'Original Tag',
			get_term($this->term_id, 'post_tag')->name,
			'An omitted name must leave the stored name unchanged.'
		);
	}

	public function test_missing_tag_id_surfaces_route_404_not_generic(): void {
		$this->actingAs('administrator');

		// An admin holds edit_post_tags (the coarse guard), so a non-existent id
		// reaches the route and surfaces its specific 404 instead of the opaque
		// ability_invalid_permissions the object-level pre-check produced.
		$result = wp_get_ability('terms/update-tag')->execute(
			array(
				'id'   => 999999,
				'name' => 'Renamed',
			)
		);

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertNotSame('ability_invalid_permissions', $result->get_error_code());
		$this->assertSame(404, $result->get_error_data()['status'] ?? null);
	}
}
