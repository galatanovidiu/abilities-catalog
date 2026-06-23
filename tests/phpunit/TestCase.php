<?php
/**
 * Base test case for the Abilities Catalog test suite.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests;

use WP_UnitTestCase;

/**
 * Shared base class.
 *
 * The plugin self-registers its abilities during the test bootstrap (the plugin
 * file is loaded on `muplugins_loaded`, which wires the Abilities API hooks).
 * This base class only provides user/role helpers that ability tests need to
 * exercise capability-gated execution.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Creates a user with the given role and makes it the current user.
	 *
	 * @param string $role WordPress role slug. Defaults to administrator.
	 * @return int The created user ID.
	 */
	protected function actingAs(string $role = 'administrator'): int {
		$user_id = self::factory()->user->create(array('role' => $role));
		wp_set_current_user($user_id);

		return $user_id;
	}

	/**
	 * Creates an administrator, grants super admin, and makes it the current user.
	 *
	 * Multisite only — call after a markTestSkipped guard on single-site.
	 *
	 * @return int The created user ID.
	 */
	protected function actingAsSuperAdmin(): int {
		$user_id = $this->actingAs('administrator');
		grant_super_admin($user_id);

		return $user_id;
	}

	/**
	 * Resets the current user after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user(0);
		parent::tear_down();
	}
}
