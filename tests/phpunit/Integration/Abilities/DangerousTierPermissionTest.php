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
		'og-plugins/install-plugin' => array('slug' => 'akismet'),
		'og-plugins/update-plugin'  => array('plugin' => 'akismet/akismet.php'),
		'og-plugins/delete-plugin'  => array('plugin' => 'akismet/akismet'),
		'og-themes/install-theme'   => array('slug' => 'twentytwentyfour'),
		'og-themes/delete-theme'    => array('stylesheet' => 'twentytwentyfour'),
		'og-updates/run-update'     => array('type' => 'plugin'),
		'og-settings/update-option' => array('name' => 'blogname', 'value' => 'x'),
		'og-privacy/generate-export' => array('request_id' => 1),
		'og-cron/schedule-event'    => array('hook' => 'abilities_catalog_test_event', 'timestamp' => 2000000000),
		'og-cron/unschedule-event'  => array('hook' => 'abilities_catalog_test_event', 'timestamp' => 2000000000),
		'og-tools/flush-object-cache' => array(),
		'og-users/destroy-all-sessions' => array(),
		'og-settings/flush-rewrite-rules' => array(),
		// Network writes are multisite + super-admin gated, so a single-site
		// administrator is denied (asserted below); the input only proves the
		// denial is the cap, not missing input. These IDs need not resolve.
		'og-network/create-site' => array('slug' => 'ac-dt-site', 'title' => 'AC DT', 'admin_id' => 1),
		'og-network/update-site' => array('blog_id' => 1, 'archived' => true),
		'og-network/delete-site' => array('blog_id' => 2),
		'og-network/grant-super-admin' => array('user_id' => 1),
		'og-network/revoke-super-admin' => array('user_id' => 1),
		'og-network/update-network-option' => array('option' => 'ac_dt_opt', 'value' => 'x'),
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

			if (str_starts_with($name, 'og-network/')) {
				// Network abilities require is_multisite() + a super-admin cap. This
				// suite runs single-site, so a plain administrator is correctly denied;
				// their permission is proven by the @group multisite per-ability tests.
				$this->assertNotTrue($result, "Single-site administrator must NOT be permitted network ability {$name} (requires multisite + super-admin).");
			} else {
				$this->assertTrue($result, "Administrator should be permitted to run {$name} on single site.");
			}
		}
	}
}
