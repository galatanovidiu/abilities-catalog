<?php
/**
 * Integration tests for the capability gate on the dangerous tier.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;

/**
 * Every dangerous ability is gated by a capability that an unprivileged user does
 * not hold. This proves the hard server-side guard for the whole tier and forces a
 * new dangerous ability to be added to the input map below (otherwise it fails).
 */
final class DangerousTierPermissionTest extends TestCase {

	/**
	 * Representative valid input per dangerous ability, so the denial is proven to
	 * come from the capability check rather than from missing input.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const VALID_INPUT = array(
		'plugins/install-plugin' => array('slug' => 'akismet'),
		'plugins/update-plugin'  => array('plugin' => 'akismet/akismet.php'),
		'plugins/delete-plugin'  => array('plugin' => 'akismet/akismet'),
		'themes/install-theme'   => array('slug' => 'twentytwentyfour'),
		'themes/delete-theme'    => array('stylesheet' => 'twentytwentyfour'),
		'updates/run-update'     => array('type' => 'plugin'),
		'settings/update-option' => array('name' => 'blogname', 'value' => 'x'),
		'privacy/generate-export' => array('request_id' => 1),
	);

	/**
	 * Discovers the names of all abilities annotated dangerous.
	 *
	 * @return array<int,string>
	 */
	private function dangerousAbilityNames(): array {
		$base  = ABILITIES_CATALOG_DIR . 'includes/Abilities/';
		$names = array();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($files as $file) {
			if (!$file->isFile() || 'php' !== $file->getExtension()) {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($base), -strlen('.php'));
			$class    = 'GalatanOvidiu\\AbilitiesCatalog\\Abilities\\' . str_replace('/', '\\', $relative);

			if (!class_exists($class) || !is_subclass_of($class, Ability::class)) {
				continue;
			}

			$ability     = new $class();
			$annotations = $ability->args()['meta']['annotations'] ?? array();

			if (true === ($annotations['dangerous'] ?? null)) {
				$names[] = $ability->name();
			}
		}

		return $names;
	}

	public function test_every_dangerous_ability_has_a_test_input(): void {
		foreach ($this->dangerousAbilityNames() as $name) {
			$this->assertArrayHasKey(
				$name,
				self::VALID_INPUT,
				"Dangerous ability {$name} has no entry in VALID_INPUT; add one so its capability gate is tested."
			);
		}
	}

	public function test_subscriber_is_denied_every_dangerous_ability(): void {
		$this->actingAs('subscriber');

		foreach ($this->dangerousAbilityNames() as $name) {
			$input  = self::VALID_INPUT[$name] ?? array();
			$result = wp_get_ability($name)->check_permissions($input);

			$this->assertNotTrue($result, "Subscriber should not be permitted to run {$name}.");
		}
	}

	public function test_administrator_is_permitted_every_dangerous_ability(): void {
		$this->actingAs('administrator');

		foreach ($this->dangerousAbilityNames() as $name) {
			$input  = self::VALID_INPUT[$name] ?? array();
			$result = wp_get_ability($name)->check_permissions($input);

			$this->assertTrue($result, "Administrator should be permitted to run {$name} on single site.");
		}
	}
}
