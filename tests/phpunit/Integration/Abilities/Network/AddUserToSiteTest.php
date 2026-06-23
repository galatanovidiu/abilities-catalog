<?php
/**
 * Integration tests for the network/add-user-to-site ability.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the ordinary multisite write that adds an existing user to a site
 * (blog) with a role: the happy-path membership grant verified by reading the
 * user's roles on that site, the idempotent role-update on re-add, the specific
 * 404/400 errors for an unknown site / user / role (which must not collapse to a
 * permission denial), and the multisite + manage_sites (super-admin) capability
 * guard (proving denied callers leave the user un-added). Skipped entirely on a
 * single-site install.
 *
 * @group multisite
 */
final class AddUserToSiteTest extends TestCase {

	/**
	 * Sites created during a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_sites = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->created_sites as $blog_id ) {
			if ( get_site( $blog_id ) ) {
				wp_delete_site( $blog_id );
			}
		}
		$this->created_sites = array();

		parent::tear_down();
	}

	/**
	 * Creates a sub-site and tracks it for cleanup.
	 *
	 * @return int The new blog ID.
	 */
	private function seedSite(): int {
		$blog_id               = self::factory()->blog->create();
		$this->created_sites[] = $blog_id;

		return $blog_id;
	}

	/**
	 * Reads a user's roles on a specific site.
	 *
	 * @param int $blog_id The site ID.
	 * @param int $user_id The user ID.
	 * @return string[] The user's role slugs on that site.
	 */
	private function rolesOnSite( int $blog_id, int $user_id ): array {
		switch_to_blog( $blog_id );
		$user  = get_userdata( $user_id );
		$roles = $user ? $user->roles : array();
		restore_current_blog();

		return $roles;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'network/add-user-to-site' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/add-user-to-site', $ability->get_name() );
	}

	public function test_happy_path_adds_user_with_role(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		$result = wp_get_ability( 'network/add-user-to-site' )->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'role'    => 'editor',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['added'] );
		$this->assertSame( $blog_id, $result['blog_id'] );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( 'editor', $result['role'] );

		// Side-effect read-back: the user now holds the role on that site.
		$this->assertContains( 'editor', $this->rolesOnSite( $blog_id, $user_id ) );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		$result = wp_get_ability( 'network/add-user-to-site' )->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'role'    => 'author',
			)
		);

		$this->assertIsArray( $result );

		$expected = array( 'added', 'blog_id', 'user_id', 'role' );
		$actual   = array_keys( $result );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );

		$this->assertIsBool( $result['added'] );
		$this->assertIsInt( $result['blog_id'] );
		$this->assertIsInt( $result['user_id'] );
		$this->assertIsString( $result['role'] );
	}

	public function test_re_adding_updates_role_idempotently(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		$ability = wp_get_ability( 'network/add-user-to-site' );

		$ability->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'role'    => 'editor',
			)
		);

		$result = $ability->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'role'    => 'author',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['added'] );
		$this->assertSame( 'author', $result['role'] );

		// set_role() replaces roles, so the user holds only the new role.
		$roles = $this->rolesOnSite( $blog_id, $user_id );
		$this->assertContains( 'author', $roles );
		$this->assertNotContains( 'editor', $roles );
	}

	public function test_unknown_user_returns_specific_404(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'network/add-user-to-site' )->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => 99999999,
				'role'    => 'editor',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_unknown_site_returns_specific_404(): void {
		$this->actingAsSuperAdmin();

		$user_id = self::factory()->user->create();

		$result = wp_get_ability( 'network/add-user-to-site' )->execute(
			array(
				'blog_id' => 99999999,
				'user_id' => $user_id,
				'role'    => 'editor',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_site_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_unknown_role_returns_400(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		$result = wp_get_ability( 'network/add-user-to-site' )->execute(
			array(
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'role'    => 'not_a_role',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_invalid_role', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The user must not have been added under the rejected role.
		$this->assertEmpty( $this->rolesOnSite( $blog_id, $user_id ) );
	}

	public function test_plain_administrator_is_denied_and_user_not_added(): void {
		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		// A plain site administrator is NOT a super admin and lacks manage_sites.
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'network/add-user-to-site' );
		$input   = array(
			'blog_id' => $blog_id,
			'user_id' => $user_id,
			'role'    => 'editor',
		);

		$this->assertNotTrue( $ability->check_permissions( $input ) );

		$result = $ability->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// A denied caller must leave the user un-added.
		$this->assertEmpty( $this->rolesOnSite( $blog_id, $user_id ) );
	}

	public function test_subscriber_is_denied(): void {
		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'network/add-user-to-site' );
		$input   = array(
			'blog_id' => $blog_id,
			'user_id' => $user_id,
			'role'    => 'editor',
		);

		$this->assertNotTrue( $ability->check_permissions( $input ) );

		$result = $ability->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$this->assertEmpty( $this->rolesOnSite( $blog_id, $user_id ) );
	}

	public function test_logged_out_user_is_denied(): void {
		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();

		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'network/add-user-to-site' );
		$input   = array(
			'blog_id' => $blog_id,
			'user_id' => $user_id,
			'role'    => 'editor',
		);

		$this->assertNotTrue( $ability->check_permissions( $input ) );

		$result = $ability->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
