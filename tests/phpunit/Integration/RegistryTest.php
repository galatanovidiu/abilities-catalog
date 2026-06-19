<?php
/**
 * Integration tests for ability registration.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Verifies that every ability class on disk actually registers with the
 * Abilities API, that each ability points at a registered category, and that
 * the consumer-facing filters expose the right subset of abilities.
 */
final class RegistryTest extends TestCase {

	/**
	 * Instantiates every ability class under includes/Abilities/<Group>/.
	 *
	 * Mirrors Registry::discover() (recursive, group-aware) so the test sees exactly
	 * the same set the Registry would register.
	 *
	 * @return array<int,Ability> Ability instances.
	 */
	private function discoverAbilities(): array {
		$base = ABILITIES_CATALOG_DIR . 'includes/Abilities/';

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
		);

		$abilities = array();
		foreach ($files as $file) {
			if (!$file->isFile() || 'php' !== $file->getExtension()) {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($base), -strlen('.php'));
			$class    = 'GalatanOvidiu\\AbilitiesCatalog\\Abilities\\' . str_replace('/', '\\', $relative);

			if (!class_exists($class) || !is_subclass_of($class, Ability::class)) {
				continue;
			}

			$abilities[] = new $class();
		}

		return $abilities;
	}

	public function test_there_are_abilities_to_register(): void {
		$this->assertNotEmpty($this->discoverAbilities(), 'No ability classes were discovered.');
	}

	public function test_every_ability_file_is_registered(): void {
		$missing = array();

		foreach ($this->discoverAbilities() as $ability) {
			if (!wp_has_ability($ability->name())) {
				$missing[] = $ability->name();
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These abilities exist on disk but failed to register (annotation guard or schema error): ' . implode(', ', $missing)
		);
	}

	public function test_every_ability_category_is_registered(): void {
		$unregistered = array();

		foreach ($this->discoverAbilities() as $ability) {
			$slug = $ability->args()['category'] ?? null;

			if (!is_string($slug) || !wp_has_ability_category($slug)) {
				$unregistered[$ability->name()] = (string) $slug;
			}
		}

		$this->assertSame(
			array(),
			$unregistered,
			'Abilities reference categories that are not registered by any CategoryProvider.'
		);
	}

	public function test_dangerous_tools_filter_lists_only_dangerous_abilities(): void {
		$tools = apply_filters('abilities_catalog_dangerous_tools', array());

		$this->assertIsArray($tools);

		foreach ($this->discoverAbilities() as $ability) {
			$annotations = $ability->args()['meta']['annotations'] ?? array();
			$is_dangerous = true === ($annotations['dangerous'] ?? null);

			if ($is_dangerous) {
				$this->assertArrayHasKey($ability->name(), $tools, $ability->name() . ' is dangerous but missing from the filter.');
			} else {
				$this->assertArrayNotHasKey($ability->name(), $tools, $ability->name() . ' is not dangerous but appears in the filter.');
			}
		}
	}

	public function test_screen_links_filter_excludes_readonly_abilities(): void {
		$links = apply_filters('abilities_catalog_screen_links', array());

		$this->assertIsArray($links);

		foreach ($this->discoverAbilities() as $ability) {
			$annotations = $ability->args()['meta']['annotations'] ?? array();

			if (true === ($annotations['readonly'] ?? null)) {
				$this->assertArrayNotHasKey($ability->name(), $links, $ability->name() . ' is read-only but has a screen link.');
			}
		}
	}
}
