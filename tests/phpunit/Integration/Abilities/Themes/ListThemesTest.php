<?php
/**
 * Integration tests for the og-themes/list-themes ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Themes;

use GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes\ListThemes;
use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * og-themes/list-themes wraps `GET /wp/v2/themes` and projects each item into a
 * flat, closed summary row via ThemeListShaper. switch_themes or
 * edit_theme_options is the coarse capability guard.
 */
final class ListThemesTest extends TestCase {

	/**
	 * The full set of keys a summary row may carry.
	 *
	 * @var string[]
	 */
	private const ROW_KEYS = array(
		'stylesheet',
		'template',
		'name',
		'status',
		'version',
		'is_block_theme',
		'author',
		'theme_uri',
		'description',
	);

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-themes/list-themes' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-themes/list-themes', $ability->get_name() );
	}

	public function test_admin_lists_themes_with_totals(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/list-themes' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );

		// Core always sets X-WP-Total / X-WP-TotalPages on success.
		$this->assertSame( count( $result['items'] ), $result['total'] );
		$this->assertSame( 1, $result['total_pages'] );
		$this->assertNotEmpty( $result['items'] );
	}

	public function test_rows_are_flat_and_closed(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/list-themes' )->execute( array() );

		$this->assertIsArray( $result );

		foreach ( $result['items'] as $row ) {
			// No key beyond the declared flat set: no _links, no nested objects.
			$this->assertSame( array(), array_diff( array_keys( $row ), self::ROW_KEYS ) );
			$this->assertIsString( $row['stylesheet'] );
			$this->assertIsString( $row['name'] );
			$this->assertIsString( $row['author'] );
			$this->assertIsBool( $row['is_block_theme'] );
		}
	}

	public function test_status_filter_returns_only_active_theme(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-themes/list-themes' )->execute(
			array( 'status' => 'active' )
		);

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['items'] );

		$stylesheets = array();
		foreach ( $result['items'] as $row ) {
			$this->assertSame( 'active', $row['status'] );
			$stylesheets[] = $row['stylesheet'];
		}

		$this->assertContains( get_stylesheet(), $stylesheets );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-themes/list-themes' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_permission_guard_checks_theme_capabilities(): void {
		$ability = new ListThemes();

		$this->actingAs( 'administrator' );
		$this->assertTrue( $ability->hasPermission( array() ) );

		$this->actingAs( 'subscriber' );
		$this->assertFalse( $ability->hasPermission( array() ) );
	}
}
