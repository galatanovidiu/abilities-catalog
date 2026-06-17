<?php
/**
 * Integration tests for the themes/get-active-theme ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes\GetActiveTheme;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * themes/get-active-theme wraps `GET /wp/v2/themes?status=active` and shapes the
 * single active item into a flat, closed field set. switch_themes or
 * edit_theme_options is the coarse capability guard.
 */
final class GetActiveThemeTest extends TestCase {

	/**
	 * The full set of output keys the flat shape may carry.
	 *
	 * @var string[]
	 */
	private const KEYS = array(
		'stylesheet',
		'template',
		'name',
		'version',
		'status',
		'is_block_theme',
		'author',
		'theme_uri',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'themes/get-active-theme' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'themes/get-active-theme', $ability->get_name() );
	}

	public function test_admin_gets_active_theme_flat_shape(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'themes/get-active-theme' )->execute();

		$this->assertIsArray( $result );
		// Closed output: no key beyond the declared set.
		$this->assertSame( array(), array_diff( array_keys( $result ), self::KEYS ) );

		// Required fields are present and match the live active theme.
		$this->assertSame( get_stylesheet(), $result['stylesheet'] );
		$this->assertNotSame( '', $result['name'] );
		$this->assertIsBool( $result['is_block_theme'] );

		// This ability only ever returns the active theme.
		$this->assertSame( 'active', $result['status'] );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'themes/get-active-theme' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_theme_capabilities(): void {
		$ability = new GetActiveTheme();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission() );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission() );
	}
}
