<?php
/**
 * Integration tests for the og-network/list-sites ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the read ability that lists a multisite network's sites as closed
 * flat rows and enforces the multisite + manage_sites (super-admin) guard.
 *
 * @group multisite
 */
final class ListSitesTest extends TestCase {

	/**
	 * The closed row field set, in declaration order.
	 *
	 * @var array<int,string>
	 */
	private const ROW_KEYS = array( 'blog_id', 'network_id', 'domain', 'path', 'url', 'registered', 'last_updated', 'public', 'archived', 'mature', 'spam', 'deleted' );

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-network/list-sites' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-network/list-sites', $ability->get_name() );
	}

	public function test_seeded_site_appears_with_the_closed_row_shape(): void {
		$this->actingAsSuperAdmin();

		$blog_id = self::factory()->blog->create();

		$result = wp_get_ability( 'og-network/list-sites' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'sites', 'total' ), array_keys( $result ) );
		$this->assertIsArray( $result['sites'] );
		$this->assertIsInt( $result['total'] );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );

		$row = $this->findRow( $result['sites'], (int) $blog_id );

		$this->assertNotNull( $row, 'The seeded site must appear as a row.' );
		$this->assertSame( (int) $blog_id, $row['blog_id'] );

		$keys = array_keys( $row );
		$expected = self::ROW_KEYS;
		sort( $keys );
		sort( $expected );
		$this->assertSame( $expected, $keys );

		$this->assertIsInt( $row['network_id'] );
		$this->assertIsString( $row['domain'] );
		$this->assertIsString( $row['path'] );
		$this->assertIsString( $row['url'] );
		$this->assertIsString( $row['registered'] );
		$this->assertIsString( $row['last_updated'] );
		$this->assertIsBool( $row['public'] );
		$this->assertIsBool( $row['archived'] );
		$this->assertIsBool( $row['mature'] );
		$this->assertIsBool( $row['spam'] );
		$this->assertIsBool( $row['deleted'] );
	}

	public function test_public_filter_returns_only_public_sites(): void {
		$this->actingAsSuperAdmin();

		self::factory()->blog->create();

		$result = wp_get_ability( 'og-network/list-sites' )->execute( array( 'public' => true ) );

		$this->assertIsArray( $result );

		foreach ( $result['sites'] as $row ) {
			$this->assertTrue( $row['public'], 'Filtering public=true must return only public sites.' );
		}
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$ability = wp_get_ability( 'og-network/list-sites' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$ability = wp_get_ability( 'og-network/list-sites' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$ability = wp_get_ability( 'og-network/list-sites' );

		$this->assertFalse( $ability->check_permissions() );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Finds the first site row matching a blog_id.
	 *
	 * @param array<int,array<string,mixed>> $sites   The projected site rows.
	 * @param int                            $blog_id The blog_id to find.
	 * @return array<string,mixed>|null The matching row, or null if absent.
	 */
	private function findRow( array $sites, int $blog_id ): ?array {
		foreach ( $sites as $row ) {
			if ( $row['blog_id'] === $blog_id ) {
				return $row;
			}
		}

		return null;
	}
}
