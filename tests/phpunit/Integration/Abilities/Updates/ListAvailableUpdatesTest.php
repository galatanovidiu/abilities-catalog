<?php
/**
 * Integration tests for the og-updates/list-available-updates ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Updates;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises registration, the four output sets, the per-row output shapes, the
 * empty/no-transient case, and the capability gate for the
 * og-updates/list-available-updates ability.
 */
final class ListAvailableUpdatesTest extends TestCase {

	/**
	 * The theme stylesheet used to seed a theme update. It must exist on disk
	 * because get_theme_updates() resolves each row through wp_get_theme().
	 */
	private const THEME_STYLESHEET = 'twentytwentyone';

	public function tear_down(): void {
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_themes' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$this->assertNotNull( wp_get_ability( 'og-updates/list-available-updates' ) );
	}

	public function test_returns_all_four_sets_for_admin(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-updates/list-available-updates' )->execute( array() );

		$this->assertIsArray( $result );
		foreach ( array( 'core', 'plugins', 'themes', 'translations' ) as $set ) {
			$this->assertArrayHasKey( $set, $result );
			$this->assertIsArray( $result[ $set ] );
		}
	}

	public function test_empty_transients_return_empty_sets(): void {
		$this->actingAs( 'administrator' );
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_themes' );

		$result = wp_get_ability( 'og-updates/list-available-updates' )->execute( array() );

		$this->assertSame( array(), $result['core'] );
		$this->assertSame( array(), $result['themes'] );
	}

	public function test_core_row_shape(): void {
		$this->actingAs( 'administrator' );
		$this->seedCoreUpdate();

		$result = wp_get_ability( 'og-updates/list-available-updates' )->execute( array( 'type' => 'core' ) );

		$this->assertNotEmpty( $result['core'] );
		$row = $result['core'][0];
		$this->assertSame(
			array( 'response', 'current', 'version', 'locale' ),
			array_keys( $row ),
			'Core rows must expose exactly the declared fields.'
		);
		$this->assertSame( 'upgrade', $row['response'] );
		$this->assertSame( '999.0.0', $row['version'] );
	}

	public function test_theme_row_shape_includes_name_and_current_version(): void {
		$this->actingAs( 'administrator' );
		$this->seedThemeUpdate();

		$result = wp_get_ability( 'og-updates/list-available-updates' )->execute( array( 'type' => 'themes' ) );

		$this->assertNotEmpty( $result['themes'] );
		$row = null;
		foreach ( $result['themes'] as $candidate ) {
			if ( self::THEME_STYLESHEET === $candidate['theme'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row, 'Seeded theme update must appear in the themes set.' );
		$this->assertSame(
			array( 'theme', 'name', 'current_version', 'new_version' ),
			array_keys( $row ),
			'Theme rows must expose exactly the declared fields.'
		);
		$this->assertSame( '999.0.0', $row['new_version'] );
		$this->assertNotSame( '', $row['name'], 'Theme name must be populated from the WP_Theme object.' );
		$this->assertNotSame( '', $row['current_version'], 'Theme current version must be populated from the WP_Theme object.' );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-updates/list-available-updates' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Seeds a single available core update into the update_core site transient.
	 *
	 * @return void
	 */
	private function seedCoreUpdate(): void {
		$offer           = new \stdClass();
		$offer->response = 'upgrade';
		$offer->current  = '6.0.0';
		$offer->version  = '999.0.0';
		$offer->locale   = 'en_US';

		$transient          = new \stdClass();
		$transient->updates = array( $offer );

		set_site_transient( 'update_core', $transient );
	}

	/**
	 * Seeds a single available theme update into the update_themes site transient.
	 *
	 * @return void
	 */
	private function seedThemeUpdate(): void {
		$transient           = new \stdClass();
		$transient->response = array(
			self::THEME_STYLESHEET => array(
				'theme'       => self::THEME_STYLESHEET,
				'new_version' => '999.0.0',
				'package'     => '',
				'url'         => '',
			),
		);

		set_site_transient( 'update_themes', $transient );
	}
}
