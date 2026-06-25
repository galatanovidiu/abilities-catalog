<?php
/**
 * Integration tests for the og-network/remove-user-from-site ability.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Site;

/**
 * Exercises the ordinary multisite membership-removal write: stripping a user's
 * roles/caps on one site by user_id + blog_id, leaving the user account intact.
 * Covers the happy path with side-effect read-back, the honest 404s for a missing
 * user and a missing site (not collapsed to a permission denial), and the
 * permission denials for a plain administrator, a subscriber, and a logged-out
 * caller. Skipped entirely on a single-site install.
 *
 * @group multisite
 */
final class RemoveUserFromSiteTest extends TestCase {

	/**
	 * Site IDs created during a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_sites = array();

	public function set_up(): void {
		parent::set_up();

		if ( is_multisite() ) {
			return;
		}

		$this->markTestSkipped( 'Network abilities require multisite.' );
	}

	public function tear_down(): void {
		foreach ( $this->created_sites as $blog_id ) {
			if ( ! get_site( $blog_id ) instanceof WP_Site ) {
				continue;
			}

			wp_delete_site( $blog_id );
		}
		$this->created_sites = array();

		parent::tear_down();
	}

	/**
	 * Creates a sub-site and records it for cleanup.
	 *
	 * @return int The new blog ID.
	 */
	private function seedSite(): int {
		$blog_id               = (int) self::factory()->blog->create();
		$this->created_sites[] = $blog_id;

		return $blog_id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/remove-user-from-site' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/remove-user-from-site', $ability->get_name() );
	}

	public function test_happy_path_removes_membership_and_reads_back(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = (int) self::factory()->user->create();

		// Seed the membership we will remove.
		add_user_to_blog( $blog_id, $user_id, 'author' );

		$result = wp_get_ability( 'og-network/remove-user-from-site' )->execute(
			array(
				'user_id' => $user_id,
				'blog_id' => $blog_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['removed'] );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( $blog_id, $result['blog_id'] );

		// Side-effect read-back: no roles on that site, but the account survives.
		switch_to_blog( $blog_id );
		$this->assertEmpty( get_userdata( $user_id )->roles );
		restore_current_blog();
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ) );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = (int) self::factory()->user->create();
		add_user_to_blog( $blog_id, $user_id, 'author' );

		$result = wp_get_ability( 'og-network/remove-user-from-site' )->execute(
			array(
				'user_id' => $user_id,
				'blog_id' => $blog_id,
			)
		);

		$this->assertIsArray( $result );

		$expected = array( 'removed', 'user_id', 'blog_id' );
		$actual   = array_keys( $result );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );

		$this->assertIsBool( $result['removed'] );
		$this->assertIsInt( $result['user_id'] );
		$this->assertIsInt( $result['blog_id'] );
	}

	public function test_unknown_user_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'og-network/remove-user-from-site' )->execute(
			array(
				'user_id' => 99999999,
				'blog_id' => $blog_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_unknown_site_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$user_id = (int) self::factory()->user->create();

		$result = wp_get_ability( 'og-network/remove-user-from-site' )->execute(
			array(
				'user_id' => $user_id,
				'blog_id' => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_site_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'og-network/remove-user-from-site' );

		$this->assertNotTrue(
			$ability->check_permissions(
				array(
					'user_id' => 1,
					'blog_id' => 1,
				)
			)
		);
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-network/remove-user-from-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'user_id' => 1,
					'blog_id' => 1,
				)
			)
		);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-network/remove-user-from-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'user_id' => 1,
					'blog_id' => 1,
				)
			)
		);
	}
}
