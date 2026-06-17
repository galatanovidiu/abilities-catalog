<?php
/**
 * Integration tests for the menus/update-menu-item ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises classic menu-item updates: happy path, the rich flat output shape,
 * the core status coercion to "draft", the negative-id schema guard, and the
 * capability guard on execute().
 */
final class UpdateMenuItemTest extends TestCase {

	/**
	 * Creates a classic menu item attached to a fresh menu and returns its ID.
	 *
	 * @param string $title The item label.
	 * @return int The new menu item ID.
	 */
	private function makeItem( string $title = 'Home' ): int {
		$menu_id = wp_create_nav_menu( 'Header Menu ' . wp_generate_uuid4() );

		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => $title,
				'menu-item-url'    => 'https://example.com/home',
				'menu-item-status' => 'publish',
			)
		);

		return (int) $item_id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'menus/update-menu-item' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'menus/update-menu-item', $ability->get_name() );
	}

	public function test_admin_updates_title_and_url(): void {
		$this->actingAs( 'administrator' );
		$item_id = $this->makeItem();

		$result = wp_get_ability( 'menus/update-menu-item' )->execute(
			array(
				'id'    => $item_id,
				'title' => 'Updated Home',
				'url'   => 'https://example.com/updated',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $item_id, $result['id'] );
		$this->assertSame( 'Updated Home', $result['title'] );
		$this->assertSame( 'https://example.com/updated', $result['url'] );
	}

	public function test_output_has_rich_flat_shape(): void {
		$this->actingAs( 'administrator' );
		$item_id = $this->makeItem();

		$result = wp_get_ability( 'menus/update-menu-item' )->execute(
			array(
				'id'    => $item_id,
				'title' => 'Shaped',
			)
		);

		$this->assertIsArray( $result );
		foreach ( array( 'id', 'title', 'url', 'type', 'object', 'object_id', 'parent', 'menu_order', 'menus', 'status' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
	}

	public function test_non_publish_status_is_coerced_to_draft(): void {
		$this->actingAs( 'administrator' );
		$item_id = $this->makeItem();

		$result = wp_get_ability( 'menus/update-menu-item' )->execute(
			array(
				'id'     => $item_id,
				'status' => 'draft',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_negative_id_is_rejected_by_schema(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'menus/update-menu-item' )->execute(
			array(
				'id'    => -7,
				'title' => 'Bad',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'administrator' );
		$item_id = $this->makeItem();

		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'menus/update-menu-item' )->execute(
			array(
				'id'    => $item_id,
				'title' => 'Denied',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
