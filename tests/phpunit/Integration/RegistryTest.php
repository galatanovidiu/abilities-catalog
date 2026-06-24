<?php
/**
 * Integration tests for ability registration.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ScopeResolver;
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

	public function test_known_non_site_domains_declare_a_non_site_scope(): void {
		$non_site_prefixes = array('network/', 'updates/', 'privacy/', 'site-health/', 'connectors/');

		foreach ($this->discoverAbilities() as $ability) {
			$name = $ability->name();

			$is_non_site_domain = false;
			foreach ($non_site_prefixes as $prefix) {
				if (0 === strpos($name, $prefix)) {
					$is_non_site_domain = true;
					break;
				}
			}

			if (!$is_non_site_domain) {
				continue;
			}

			$scope = ScopeResolver::resolve($ability->args(), $name);

			$this->assertNotSame(
				'site',
				$scope,
				$name . ' is in a non-site domain but resolves to the site scope; declare meta.abilities_catalog.scope (network/user/global).'
			);
		}
	}

	public function test_single_site_decorator_is_a_no_op_for_every_ability(): void {
		// The hint sentence the multisite decorator appends to site-scoped descriptions.
		// On single-site the decorator must never append it. A distinctive substring is
		// enough to catch it (PolicyDecorator::appendMultisiteHint()).
		$hint_substring = 'pass blog_id to target a specific site';

		// The internal flag the decorator writes ONLY on the fully-wrapped (multisite)
		// path. On single-site it must never appear in the registered meta (PLAN.md §7,
		// F6 — proves the wiring changed nothing).
		$decorated_flag = '_abilities_catalog_decorated';

		foreach ($this->discoverAbilities() as $ability) {
			$name       = $ability->name();
			$registered = wp_get_ability($name);

			$this->assertNotNull($registered, $name . ' is not registered.');

			// 1. The decorator INJECTS a blog_id only where the schema lacks one, so the
			// no-op proof is that the registered blog_id presence matches the class's own.
			// A native blog_id (the Network site-management abilities take one as a real
			// param) must survive; an ability without one must not gain one.
			$schema     = $registered->get_input_schema();
			$properties = isset($schema['properties']) && is_array($schema['properties'])
				? $schema['properties']
				: array();
			$native_schema = $ability->args()['input_schema'] ?? array();
			$native_props  = is_array($native_schema) && isset($native_schema['properties']) && is_array($native_schema['properties'])
				? $native_schema['properties']
				: array();
			if (array_key_exists('blog_id', $native_props)) {
				$this->assertArrayHasKey(
					'blog_id',
					$properties,
					$name . ' lost its native blog_id property on single-site.'
				);
			} else {
				$this->assertArrayNotHasKey(
					'blog_id',
					$properties,
					$name . ' has an injected blog_id on single-site; the decorator should be a no-op.'
				);
			}

			// 2. The registered description carries no multisite hint sentence.
			$this->assertStringNotContainsString(
				$hint_substring,
				$registered->get_description(),
				$name . ' has the multisite hint in its description on single-site.'
			);

			// 3. Strong form: the registered meta has the same content the ability class's
			// own args() declared — proof the decorator mutated nothing on single-site.
			// Core's get_meta() merges defaults via wp_parse_args, which REORDERS the meta
			// keys (e.g. show_in_rest moves relative to abilities_catalog), so an
			// order-sensitive assertSame gives a false failure; assertEquals compares
			// content regardless of order and still catches any added key (the decorator
			// flag, also asserted absent above) or changed value.
			$registered_meta = $registered->get_meta();
			$this->assertArrayNotHasKey(
				$decorated_flag,
				$registered_meta,
				$name . ' carries the decorator flag on single-site; the decorator wrote meta it should not have.'
			);
			$this->assertEquals(
				$ability->args()['meta'],
				$registered_meta,
				$name . ' registered meta differs from its class args() meta on single-site (the decorator mutated meta).'
			);
		}
	}
}
