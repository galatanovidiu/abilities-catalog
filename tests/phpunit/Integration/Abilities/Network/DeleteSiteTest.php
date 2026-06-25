<?php
/**
 * Integration tests for the og-network/delete-site ability.
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
 * Exercises the dangerous, irreversible multisite write: permanently deleting one
 * site (blog) by blog_id. Covers the happy path with a gone-after read-back, the
 * ability-owned main-site guard (core does not block it), the dedicated 404 for an
 * unknown id (which must not collapse to a permission denial), and the
 * dangerous-tier capability guard (multisite + manage_sites repeated at the top of
 * execute(), proving a non-super-admin cannot delete a site). Skipped entirely on a
 * single-site install.
 *
 * @group multisite
 */
final class DeleteSiteTest extends TestCase {

	/**
	 * Sub-site IDs seeded by a test, deleted in tearDown if they survived.
	 *
	 * @var array<int,int>
	 */
	private array $seeded_sites = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->seeded_sites as $blog_id ) {
			if ( get_site( $blog_id ) instanceof WP_Site ) {
				wp_delete_site( $blog_id );
			}
		}
		$this->seeded_sites = array();

		parent::tear_down();
	}

	/**
	 * Seeds a sub-site and records it for cleanup.
	 *
	 * @return int The new blog ID.
	 */
	private function seedSite(): int {
		$blog_id = self::factory()->blog->create();

		$this->seeded_sites[] = $blog_id;

		return $blog_id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/delete-site' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/delete-site', $ability->get_name() );
	}

	public function test_happy_path_permanently_deletes_the_site(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'og-network/delete-site' )->execute( array( 'blog_id' => $blog_id ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $blog_id, $result['blog_id'] );

		// Read-back: the site is gone.
		$this->assertNull( get_site( $blog_id ) );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'og-network/delete-site' )->execute( array( 'blog_id' => $blog_id ) );

		$this->assertIsArray( $result );

		$expected = array( 'deleted', 'blog_id' );
		$actual   = array_keys( $result );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );

		$this->assertIsBool( $result['deleted'] );
		$this->assertIsInt( $result['blog_id'] );
	}

	public function test_main_site_cannot_be_deleted(): void {
		$this->actingAsSuperAdmin();

		$main_id = get_main_site_id();

		$result = wp_get_ability( 'og-network/delete-site' )->execute( array( 'blog_id' => $main_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cannot_delete_main_site', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		// The main site survives.
		$this->assertInstanceOf( WP_Site::class, get_site( $main_id ) );
	}

	public function test_unknown_id_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/delete-site' )->execute( array( 'blog_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'site_not_exist', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The specific not-found error must not collapse into the generic
		// permission denial.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied_and_site_survives(): void {
		// A plain site administrator is NOT a super admin and lacks manage_sites.
		$this->actingAs( 'administrator' );

		$blog_id = $this->seedSite();

		$ability = wp_get_ability( 'og-network/delete-site' );

		$this->assertNotTrue( $ability->check_permissions( array( 'blog_id' => $blog_id ) ) );

		// The public execute() runs check_permissions() first and returns the
		// generic permission error without invoking the ability's own callback.
		$result = $ability->execute( array( 'blog_id' => $blog_id ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The site survived the denied call.
		$this->assertInstanceOf( WP_Site::class, get_site( $blog_id ) );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-network/delete-site' );

		$this->assertNotTrue( $ability->check_permissions( array( 'blog_id' => 2 ) ) );

		$result = $ability->execute( array( 'blog_id' => 2 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-network/delete-site' );

		$this->assertNotTrue( $ability->check_permissions( array( 'blog_id' => 2 ) ) );

		$result = $ability->execute( array( 'blog_id' => 2 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
