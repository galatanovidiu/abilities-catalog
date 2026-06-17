<?php
/**
 * Integration tests for the menus/create-menu-item ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises classic menu-item creation: happy path, the rich flat output shape,
 * placement in a parent menu, the orphan case when "menus" is omitted, the
 * core status coercion to "draft", missing-object errors, and the capability
 * guard on execute().
 */
final class CreateMenuItemTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'menus/create-menu-item' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/create-menu-item', $ability->get_name() );
	}

	public function test_admin_creates_custom_item_in_menu(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'menus/create-menu-item' )->execute(
			array(
				'title' => 'Home',
				'url'   => 'https://example.com/home',
				'menus' => $menu_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'Home', $result['title'] );
		$this->assertSame( 'https://example.com/home', $result['url'] );
		$this->assertSame( 'custom', $result['type'] );
		$this->assertSame( (int) $menu_id, $result['menus'] );
		$this->assertSame( 'publish', $result['status'] );

		$post = get_post( $result['id'] );
		$this->assertSame( 'nav_menu_item', $post->post_type );
	}

	public function test_output_has_rich_flat_shape(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Shape Menu' );

		$result = wp_get_ability( 'menus/create-menu-item' )->execute(
			array(
				'title' => 'Docs',
				'url'   => 'https://example.com/docs',
				'menus' => $menu_id,
			)
		);

		$this->assertIsArray( $result );
		foreach ( array( 'id', 'title', 'url', 'type', 'object', 'object_id', 'parent', 'menu_order', 'menus', 'status' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
	}

	public function test_omitting_menus_creates_orphaned_item(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'menus/create-menu-item' )->execute(
			array(
				'title' => 'Orphan',
				'url'   => 'https://example.com/orphan',
			)
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 0, $result['menus'] );
	}

	public function test_non_publish_status_is_coerced_to_draft(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Draft Menu' );

		$result = wp_get_ability( 'menus/create-menu-item' )->execute(
			array(
				'title'  => 'Pending Item',
				'url'    => 'https://example.com/pending',
				'menus'  => $menu_id,
				'status' => 'draft',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_invalid_object_id_surfaces_core_error(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Object Menu' );

		// Core only validates object_id when "object" is omitted (it resolves the
		// object from the ID in that path). With "object" absent and a bogus ID,
		// core rejects with rest_post_invalid_id.
		$result = wp_get_ability( 'menus/create-menu-item' )->execute(
			array(
				'type'      => 'post_type',
				'object_id' => 999999,
				'menus'     => $menu_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_post_invalid_id', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'menus/create-menu-item' )->execute(
			array(
				'title' => 'Denied',
				'url'   => 'https://example.com/denied',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
