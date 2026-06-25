<?php
/**
 * Integration tests for the og-menus/delete-menu-item ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the T2 destructive write ability: permanently deletes a single
 * classic menu item, with the capability guard, the missing-object error, the
 * negative-id rejection before any coercion, and the snapshot output shape
 * (previous_title, previous_menus) drawn from the REST `previous`.
 */
final class DeleteMenuItemTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/delete-menu-item' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/delete-menu-item', $ability->get_name() );
	}

	public function test_admin_permanently_deletes_item(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => 'https://example.com/home',
				'menu-item-status' => 'publish',
			)
		);

		$result = wp_get_ability( 'og-menus/delete-menu-item' )->execute(
			array( 'id' => $item_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( (int) $item_id, $result['id'] );

		// Item is gone permanently (no Trash for menu items).
		$this->assertNull( get_post( $item_id ) );
	}

	public function test_output_reports_destroyed_item_snapshot(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Located Menu' );
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Docs',
				'menu-item-url'    => 'https://example.com/docs',
				'menu-item-status' => 'publish',
			)
		);

		$result = wp_get_ability( 'og-menus/delete-menu-item' )->execute(
			array( 'id' => $item_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Docs', $result['previous_title'] );
		$this->assertSame( (int) $menu_id, $result['previous_menus'] );
	}

	public function test_negative_id_is_rejected_before_deletion(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Guard Menu' );
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Keep',
				'menu-item-url'    => 'https://example.com/keep',
				'menu-item-status' => 'publish',
			)
		);

		// A negative id must be rejected by schema validation (minimum:1) before
		// absint() could coerce -$item_id into the positive $item_id and delete it.
		$result = wp_get_ability( 'og-menus/delete-menu-item' )->execute(
			array( 'id' => -$item_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $item_id ) );
	}

	public function test_missing_item_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// An admin holds edit_theme_options (the coarse guard), so a non-existent id
		// reaches the route and surfaces its specific 404 instead of the opaque
		// ability_invalid_permissions the object-level pre-check produced.
		$result = wp_get_ability( 'og-menus/delete-menu-item' )->execute(
			array( 'id' => 999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Denied',
				'menu-item-url'    => 'https://example.com/denied',
				'menu-item-status' => 'publish',
			)
		);

		$result = wp_get_ability( 'og-menus/delete-menu-item' )->execute(
			array( 'id' => $item_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
