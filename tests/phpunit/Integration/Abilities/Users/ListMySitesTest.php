<?php
/**
 * Integration tests for the users/list-my-sites ability.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the user-scoped multisite discovery read that lists the sites the
 * current user is a member of: the registered contract, the logged-in-ONLY
 * permission guard (which deliberately does NOT gate on capability or
 * is_multisite()), the friendly multisite-only WP_Error on a single-site install
 * (which must NOT collapse to a generic permission denial), and — under the
 * multisite group — the happy-path membership list with its exact closed row shape.
 *
 * @group multisite
 */
final class ListMySitesTest extends TestCase {

	/**
	 * Sites created during a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_sites = array();

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

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'users/list-my-sites' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'users/list-my-sites', $ability->get_name() );
	}

	/**
	 * The permission guard is logged-in ONLY: a logged-in subscriber passes (so it
	 * does not gate on capability), and a logged-out caller fails. This also pins
	 * that the guard does NOT gate on is_multisite() — the subscriber passes on a
	 * single-site CI run, where the multisite-only outcome is surfaced by execute().
	 */
	public function test_permission_is_logged_in_only(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'users/list-my-sites' );

		// A logged-in subscriber passes: no capability is required.
		$this->assertTrue( $ability->check_permissions() );

		wp_set_current_user( 0 );

		// A logged-out caller is denied.
		$this->assertNotTrue( $ability->check_permissions() );
	}

	/**
	 * On a single-site install a logged-in caller reaches execute(), which returns
	 * the friendly multisite-only WP_Error rather than collapsing to a generic
	 * permission denial. Skipped on multisite, where the happy path applies instead.
	 */
	public function test_single_site_returns_friendly_multisite_error(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site-only behaviour; multisite is covered by the happy-path test.' );
		}

		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'users/list-my-sites' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_requires_multisite', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Multisite happy path: a user added to a sub-site sees it in the list, with the
	 * exact closed per-row key-set and correct field types, and total === count(sites).
	 *
	 * @group multisite
	 */
	public function test_multisite_lists_the_users_sites(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Listing a user\'s sites requires multisite.' );
		}

		$blog_id = $this->seedSite();
		$user_id = self::factory()->user->create();
		add_user_to_blog( $blog_id, $user_id, 'editor' );

		wp_set_current_user( $user_id );

		$result = wp_get_ability( 'users/list-my-sites' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( 'sites', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['sites'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['sites'] ), $result['total'] );

		// The created sub-site appears in the membership list.
		$blog_ids = array();
		foreach ( $result['sites'] as $row ) {
			$blog_ids[] = $row['blog_id'];
		}
		$this->assertContains( $blog_id, $blog_ids );

		// Each row carries exactly the closed field set with correct types.
		$this->assertNotEmpty( $result['sites'] );
		foreach ( $result['sites'] as $row ) {
			$this->assertSame(
				array( 'blog_id', 'name', 'url', 'path' ),
				array_keys( $row )
			);
			$this->assertIsInt( $row['blog_id'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['url'] );
			$this->assertIsString( $row['path'] );
		}
	}
}
