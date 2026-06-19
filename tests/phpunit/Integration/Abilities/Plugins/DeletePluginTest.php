<?php
/**
 * Integration tests for the plugins/delete-plugin capability gate (B15).
 *
 * delete-plugin mirrors core's delete_item_permissions_check(), which requires BOTH
 * activate_plugins and delete_plugins (class-wp-rest-plugins-controller.php). The
 * load-bearing test proves a user holding only delete_plugins is now denied. No
 * happy-path delete test runs — that would delete a real plugin from disk, matching
 * the dangerous-tier convention of asserting only the gate and contract here.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises registration, the both-caps gate, and the empty-input rejection.
 */
final class DeletePluginTest extends TestCase {

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'plugins/delete-plugin' ) );
	}

	/**
	 * delete_plugins alone is not enough: core (and now the coarse gate) also require
	 * activate_plugins. A user holding only delete_plugins must be refused.
	 */
	public function test_user_with_only_delete_plugins_is_denied(): void {
		$this->actingAs( 'subscriber' );
		// Mutate the live current user in place: wp_set_current_user() short-circuits
		// for the same id, so re-setting would not pick up a persisted cap.
		wp_get_current_user()->add_cap( 'delete_plugins' );

		// Precondition: the user genuinely lacks activate_plugins, so the test detects
		// a regression to a delete_plugins-only gate.
		$this->assertFalse( current_user_can( 'activate_plugins' ) );
		$this->assertTrue( current_user_can( 'delete_plugins' ) );

		$allowed = wp_get_ability( 'plugins/delete-plugin' )->check_permissions( array( 'plugin' => 'hello' ) );

		$this->assertNotTrue( $allowed );
	}

	public function test_administrator_is_permitted(): void {
		$this->actingAs( 'administrator' );

		$allowed = wp_get_ability( 'plugins/delete-plugin' )->check_permissions( array( 'plugin' => 'hello' ) );

		$this->assertTrue( $allowed );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$allowed = wp_get_ability( 'plugins/delete-plugin' )->check_permissions( array( 'plugin' => 'hello' ) );

		$this->assertNotTrue( $allowed );
	}

	public function test_empty_plugin_is_rejected(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'plugins/delete-plugin' )->execute( array( 'plugin' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'ability_invalid_input', 'ability_invalid_permissions', 'webmcp_missing_plugin' )
		);
	}
}
