<?php
/**
 * Integration tests for the network/get-site ability.
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
 * Exercises the single-object multisite read: one site (blog) by blog_id,
 * against the declared closed projection (twelve list-sites fields plus
 * blogname and siteurl), the dedicated 404 for an unknown id (which must not
 * collapse to a permission denial), and the multisite + manage_sites
 * (super-admin) capability guard. Skipped entirely on a single-site install.
 *
 * @group multisite
 */
final class GetSiteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'network/get-site' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'network/get-site', $ability->get_name() );
	}

	public function test_happy_path_returns_the_seeded_site(): void {
		$this->actingAsSuperAdmin();

		$blog_id = self::factory()->blog->create();

		$result = wp_get_ability( 'network/get-site' )->execute( array( 'blog_id' => $blog_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $blog_id, $result['blog_id'] );
		$this->assertIsString( $result['blogname'] );
		$this->assertNotSame( '', $result['blogname'] );
		$this->assertIsString( $result['siteurl'] );
		$this->assertNotSame( '', $result['siteurl'] );
	}

	public function test_result_has_exactly_the_closed_field_set(): void {
		$this->actingAsSuperAdmin();

		$blog_id = self::factory()->blog->create();

		$result = wp_get_ability( 'network/get-site' )->execute( array( 'blog_id' => $blog_id ) );

		$this->assertIsArray( $result );

		$expected = array( 'blog_id', 'network_id', 'domain', 'path', 'url', 'registered', 'last_updated', 'public', 'archived', 'mature', 'spam', 'deleted', 'blogname', 'siteurl' );
		$actual   = array_keys( $result );
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );

		$this->assertIsInt( $result['blog_id'] );
		$this->assertIsInt( $result['network_id'] );
		$this->assertIsString( $result['domain'] );
		$this->assertIsString( $result['path'] );
		$this->assertIsString( $result['url'] );
		$this->assertIsString( $result['registered'] );
		$this->assertIsString( $result['last_updated'] );
		$this->assertIsBool( $result['public'] );
		$this->assertIsBool( $result['archived'] );
		$this->assertIsBool( $result['mature'] );
		$this->assertIsBool( $result['spam'] );
		$this->assertIsBool( $result['deleted'] );
		$this->assertIsString( $result['blogname'] );
		$this->assertIsString( $result['siteurl'] );
	}

	public function test_unknown_id_returns_specific_404_not_permission_collapse(): void {
		$this->actingAsSuperAdmin();

		$result = wp_get_ability( 'network/get-site' )->execute( array( 'blog_id' => 99999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_site_invalid_id', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );

		// The specific not-found error must not collapse into the generic
		// permission denial.
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		// A plain site administrator is NOT a super admin and lacks manage_sites.
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'network/get-site' );

		$this->assertFalse( $ability->check_permissions( array( 'blog_id' => 1 ) ) );

		$result = $ability->execute( array( 'blog_id' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_has_no_permission(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'network/get-site' );

		$this->assertFalse( $ability->check_permissions( array( 'blog_id' => 1 ) ) );

		$result = $ability->execute( array( 'blog_id' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'network/get-site' );

		$this->assertFalse( $ability->check_permissions( array( 'blog_id' => 1 ) ) );

		$result = $ability->execute( array( 'blog_id' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
