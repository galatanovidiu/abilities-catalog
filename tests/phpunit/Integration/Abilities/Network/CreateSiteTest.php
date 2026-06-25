<?php
/**
 * Integration tests for the og-network/create-site ability.
 *
 * @package AbilitiesCatalog\Tests
 *
 * @group multisite
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network\CreateSite;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_Site;

/**
 * Exercises the dangerous multisite write: create a new site (blog) under a
 * slug with a title and an existing admin user, against the declared closed
 * output (blog_id + url), the dedicated 404 for an unknown admin_id (which must
 * not collapse to a permission denial), and the multisite + create_sites
 * (super-admin) capability guard — including the execute()-top cap repeat that
 * denies a plain administrator. Skipped entirely on a single-site install.
 *
 * @group multisite
 */
final class CreateSiteTest extends TestCase {

	/**
	 * Blog IDs created by a test, deleted in tear_down().
	 *
	 * @var int[]
	 */
	private array $created_blog_ids = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function tear_down(): void {
		foreach ( $this->created_blog_ids as $blog_id ) {
			if ( get_site( $blog_id ) instanceof WP_Site ) {
				wp_delete_site( $blog_id );
			}
		}
		$this->created_blog_ids = array();

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/create-site' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/create-site', $ability->get_name() );
	}

	public function test_happy_path_creates_a_site(): void {
		$this->actingAsSuperAdmin();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = wp_get_ability( 'og-network/create-site' )->execute(
			array(
				'slug'     => 'ac-new-' . uniqid(),
				'title'    => 'AC New',
				'admin_id' => $admin_id,
			)
		);

		$this->assertIsArray( $result );
		$this->created_blog_ids[] = $result['blog_id'];

		$this->assertIsInt( $result['blog_id'] );
		$this->assertGreaterThan( 0, $result['blog_id'] );
		$this->assertInstanceOf( WP_Site::class, get_site( $result['blog_id'] ) );
		$this->assertIsString( $result['url'] );
		$this->assertNotSame( '', $result['url'] );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$result = wp_get_ability( 'og-network/create-site' )->execute(
			array(
				'slug'     => 'ac-keys-' . uniqid(),
				'title'    => 'AC Keys',
				'admin_id' => $admin_id,
			)
		);

		$this->assertIsArray( $result );
		$this->created_blog_ids[] = $result['blog_id'];

		$this->assertSame( array( 'blog_id', 'url' ), array_keys( $result ) );
		$this->assertIsInt( $result['blog_id'] );
		$this->assertIsString( $result['url'] );
	}

	public function test_unknown_admin_id_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'og-network/create-site' )->execute(
			array(
				'slug'     => 'ac-bad-admin-' . uniqid(),
				'title'    => 'AC Bad Admin',
				'admin_id' => 99999999,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_user_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		// A plain site administrator is NOT a super admin and lacks create_sites.
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'og-network/create-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'slug'     => 'ac-denied',
					'title'    => 'AC Denied',
					'admin_id' => 1,
				)
			)
		);

		// The WP_Ability::execute() wrapper runs check_permissions() first and would
		// short-circuit with 'ability_invalid_permissions' before the class body, so
		// instantiate the class directly to reach and prove the execute()-top cap repeat.
		$result = ( new CreateSite() )->execute(
			array(
				'slug'     => 'ac-denied',
				'title'    => 'AC Denied',
				'admin_id' => 1,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'abilities_catalog_cannot_create_sites', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-network/create-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'slug'     => 'ac-sub',
					'title'    => 'AC Sub',
					'admin_id' => 1,
				)
			)
		);
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-network/create-site' );

		$this->assertFalse(
			$ability->check_permissions(
				array(
					'slug'     => 'ac-out',
					'title'    => 'AC Out',
					'admin_id' => 1,
				)
			)
		);
	}
}
