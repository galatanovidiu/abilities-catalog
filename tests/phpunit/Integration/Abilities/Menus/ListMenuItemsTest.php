<?php
/**
 * Integration tests for the menus/list-menu-items ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises classic menu-item listing: happy-path collection, scoping by the
 * "menus" filter, the output shape (items/total/total_pages), and the
 * capability guard on execute().
 */
final class ListMenuItemsTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'menus/list-menu-items' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/list-menu-items', $ability->get_name() );
	}

	public function test_admin_lists_menu_items(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );
		$this->seedItem( $menu_id, 'Home', 'https://example.com/home' );
		$this->seedItem( $menu_id, 'About', 'https://example.com/about' );

		$result = wp_get_ability( 'menus/list-menu-items' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['total_pages'] );
	}

	public function test_menus_filter_scopes_to_a_single_menu(): void {
		$this->actingAs( 'administrator' );
		$menu_a = wp_create_nav_menu( 'Menu A' );
		$menu_b = wp_create_nav_menu( 'Menu B' );
		$this->seedItem( $menu_a, 'A1', 'https://example.com/a1' );
		$this->seedItem( $menu_a, 'A2', 'https://example.com/a2' );
		$this->seedItem( $menu_b, 'B1', 'https://example.com/b1' );

		$result = wp_get_ability( 'menus/list-menu-items' )->execute(
			array( 'menus' => (int) $menu_a )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['items'] );
		foreach ( $result['items'] as $item ) {
			$this->assertSame( (int) $menu_a, $item['menus'] );
		}
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'menus/list-menu-items' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Creates a custom classic menu item in the given menu.
	 *
	 * @param int    $menu_id Classic menu (term) ID.
	 * @param string $title   Item title.
	 * @param string $url     Item URL.
	 * @return int The created menu item (post) ID.
	 */
	private function seedItem( int $menu_id, string $title, string $url ): int {
		return (int) wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => $title,
				'menu-item-url'    => $url,
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
	}
}
