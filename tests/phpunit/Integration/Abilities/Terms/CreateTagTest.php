<?php
/**
 * Integration tests for the terms/create-tag ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the create-tag write ability: registration, the happy path, the
 * id/name/slug/link output shape, capability gating, and duplicate-name error
 * specificity.
 */
final class CreateTagTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability('terms/create-tag');

		$this->assertNotNull($ability);
		$this->assertSame('terms/create-tag', $ability->get_name());
	}

	/**
	 * Happy path: an administrator creates a tag and the stored term matches the
	 * returned id/name/slug, with a non-empty archive link.
	 */
	public function test_creates_tag_and_returns_core_shape(): void {
		$this->actingAs('administrator');

		$result = wp_get_ability('terms/create-tag')->execute(
			array(
				'name' => 'Featured',
				'slug' => 'featured',
			)
		);

		$this->assertIsArray($result);
		$this->assertGreaterThan(0, $result['id']);
		$this->assertSame('Featured', $result['name']);
		$this->assertSame('featured', $result['slug']);
		$this->assertIsString($result['link']);
		$this->assertNotSame('', $result['link']);

		$term = get_term($result['id'], 'post_tag');
		$this->assertSame('Featured', $term->name);
		$this->assertSame('featured', $term->slug);
	}

	/**
	 * Capability gating: a subscriber lacks the `post_tag` `assign_terms`
	 * capability, so the ability's permission check denies execution.
	 */
	public function test_subscriber_cannot_create_tag(): void {
		$this->actingAs('subscriber');

		$ability = wp_get_ability('terms/create-tag');

		$this->assertFalse($ability->check_permissions(array('name' => 'Denied')));
	}

	/**
	 * Error specificity: creating a tag whose name already exists surfaces core's
	 * `term_exists` error code through the shared RestError helper.
	 */
	public function test_duplicate_name_surfaces_term_exists_error(): void {
		$this->actingAs('administrator');

		self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Duplicate',
				'slug'     => 'duplicate',
			)
		);

		$result = wp_get_ability('terms/create-tag')->execute(
			array(
				'name' => 'Duplicate',
				'slug' => 'duplicate',
			)
		);

		$this->assertInstanceOf(WP_Error::class, $result);
		$this->assertSame('term_exists', $result->get_error_code());
	}
}
