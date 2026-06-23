<?php
/**
 * Integration tests for the network/count-sites ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Network;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * network/count-sites is a multisite read returning site counts grouped by
 * status. It wraps wp_count_sites(), which returns exactly six keys (no
 * `empty`). manage_sites (super-admin) is the hard guard.
 *
 * @group multisite
 */
final class CountSitesTest extends TestCase {

	/**
	 * The full output field set (sorted).
	 *
	 * @var string[]
	 */
	private const FIELDS = array(
		'all',
		'archived',
		'deleted',
		'mature',
		'public',
		'spam',
	);

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Network abilities require multisite.' );
		}
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'network/count-sites' ) );
	}

	public function test_super_admin_gets_counts_with_exact_six_keys(): void {
		$this->actingAsSuperAdmin();

		self::factory()->blog->create();

		$result = wp_get_ability( 'network/count-sites' )->execute( array() );

		$this->assertIsArray( $result );

		$keys = array_keys( $result );
		sort( $keys );
		$this->assertSame( self::FIELDS, $keys );

		foreach ( self::FIELDS as $field ) {
			$this->assertIsInt( $result[ $field ], "$field must be an integer" );
		}

		$this->assertGreaterThanOrEqual( 2, $result['all'], 'Main site plus the seeded site.' );

		$this->assertArrayNotHasKey( 'empty', $result, 'wp_count_sites() returns no empty key.' );
	}

	public function test_plain_administrator_is_denied(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'network/count-sites' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'network/count-sites' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'network/count-sites' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
