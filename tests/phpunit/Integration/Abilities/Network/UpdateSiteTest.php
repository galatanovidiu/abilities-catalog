<?php
/**
 * Integration tests for the network/update-site ability.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network\UpdateSite;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Site;

/**
 * Exercises the dangerous multisite site-update write: toggling a site's status
 * flags by blog_id and reading the updated WP_Site back. Covers the happy path
 * with side-effect read-back, idempotent re-apply, the no-changes 400 guard, the
 * dedicated 404 for an unknown id (not collapsed to a permission denial), and the
 * dangerous-tier denial proving a non-super-admin cannot mutate. Skipped entirely
 * on a single-site install.
 *
 * @group multisite
 */
final class UpdateSiteTest extends TestCase {

	/**
	 * Site IDs created during a test, deleted in tear_down().
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
			if ( get_site( $blog_id ) instanceof WP_Site ) {
				wp_delete_site( $blog_id );
			}
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
		$ability = wp_get_ability( 'network/update-site' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/update-site', $ability->get_name() );
	}

	public function test_happy_path_archives_the_site_and_reads_back(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'network/update-site' )->execute(
			array(
				'blog_id'  => $blog_id,
				'archived' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $blog_id, $result['blog_id'] );
		$this->assertTrue( $result['archived'] );

		// Side-effect read-back via core.
		$this->assertTrue( (bool) (int) get_site( $blog_id )->archived );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'network/update-site' )->execute(
			array(
				'blog_id'  => $blog_id,
				'archived' => true,
			)
		);

		$this->assertIsArray( $result );

		$expected = array( 'blog_id', 'domain', 'path', 'public', 'archived', 'mature', 'spam', 'deleted' );
		$actual   = array_keys( $result );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );

		$this->assertIsInt( $result['blog_id'] );
		$this->assertIsString( $result['domain'] );
		$this->assertIsString( $result['path'] );
		$this->assertIsBool( $result['public'] );
		$this->assertIsBool( $result['archived'] );
		$this->assertIsBool( $result['mature'] );
		$this->assertIsBool( $result['spam'] );
		$this->assertIsBool( $result['deleted'] );
	}

	public function test_reapplying_the_same_flag_is_idempotent(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$first = wp_get_ability( 'network/update-site' )->execute(
			array(
				'blog_id'  => $blog_id,
				'archived' => true,
			)
		);
		$this->assertIsArray( $first );
		$this->assertTrue( $first['archived'] );

		// Re-applying the same flag is a same-state no-op, not an error.
		$second = wp_get_ability( 'network/update-site' )->execute(
			array(
				'blog_id'  => $blog_id,
				'archived' => true,
			)
		);
		$this->assertIsArray( $second );
		$this->assertTrue( $second['archived'] );
		$this->assertTrue( (bool) (int) get_site( $blog_id )->archived );
	}

	public function test_no_changes_returns_400(): void {
		$this->actingAsSuperAdmin();

		$blog_id = $this->seedSite();

		$result = wp_get_ability( 'network/update-site' )->execute( array( 'blog_id' => $blog_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_no_changes', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_unknown_id_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/update-site' )->execute(
			array(
				'blog_id'  => 99999999,
				'archived' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'site_not_exist', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied_and_site_unchanged(): void {
		$this->actingAsSuperAdmin();
		$blog_id = $this->seedSite();

		// A plain site administrator is NOT a super admin and lacks manage_sites.
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'network/update-site' );

		$this->assertNotTrue(
			$ability->check_permissions(
				array(
					'blog_id'  => $blog_id,
					'archived' => true,
				)
			)
		);

		// The WP_Ability::execute() wrapper runs check_permissions() first and would
		// short-circuit with 'ability_invalid_permissions' before the class body, so
		// instantiate the class directly to reach and prove the execute()-top cap repeat.
		$result = ( new UpdateSite() )->execute(
			array(
				'blog_id'  => $blog_id,
				'archived' => true,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cannot_manage_sites', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );

		// The site survived unchanged.
		$this->assertFalse( (bool) (int) get_site( $blog_id )->archived );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'network/update-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'blog_id'  => 1,
					'archived' => true,
				)
			)
		);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'network/update-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'blog_id'  => 1,
					'archived' => true,
				)
			)
		);
	}
}
