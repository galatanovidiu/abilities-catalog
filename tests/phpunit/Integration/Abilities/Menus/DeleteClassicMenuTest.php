<?php
/**
 * Integration tests for the og-menus/delete-classic-menu ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the adapter-backed T2 destructive write ability: permanently deletes
 * a classic menu, with the route's capability check, its missing-object 404, and
 * the snapshot output shape (name, slug, removed_locations) drawn from the REST
 * `previous` body. Denials surface through execute() as the route's REAL REST
 * error, not the generic ability_invalid_permissions.
 */
final class DeleteClassicMenuTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		register_nav_menu( 'ac_primary', 'AC Primary' );
	}

	public function tear_down(): void {
		unregister_nav_menu( 'ac_primary' );
		remove_theme_mod( 'nav_menu_locations' );
		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/delete-classic-menu' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/delete-classic-menu', $ability->get_name() );
	}

	public function test_admin_permanently_deletes_menu(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		$result = wp_get_ability( 'og-menus/delete-classic-menu' )->execute(
			array( 'id' => $menu_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( (int) $menu_id, $result['id'] );

		// Term is gone permanently (no Trash for classic menus).
		$this->assertFalse( get_term( $menu_id, 'nav_menu' ) instanceof \WP_Term );
	}

	public function test_output_reports_destroyed_menu_snapshot(): void {
		$this->actingAs( 'administrator' );
		$menu_id = wp_create_nav_menu( 'Located Menu' );
		set_theme_mod( 'nav_menu_locations', array( 'ac_primary' => (int) $menu_id ) );

		$result = wp_get_ability( 'og-menus/delete-classic-menu' )->execute(
			array( 'id' => $menu_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Located Menu', $result['name'] );
		$this->assertSame( 'located-menu', $result['slug'] );
		$this->assertContains( 'ac_primary', $result['removed_locations'] );
	}

	public function test_missing_menu_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// The adapter's permission phase is guard-only and this ability has no
		// require_permission floor, so a non-existent id reaches the route at
		// dispatch and surfaces the terms controller's real rest_term_invalid 404
		// through execute() — not the generic ability_invalid_permissions.
		$result = wp_get_ability( 'og-menus/delete-classic-menu' )->execute(
			array( 'id' => 999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'rest_term_invalid', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_subscriber_is_denied_with_route_error(): void {
		$this->actingAs( 'subscriber' );
		$menu_id = wp_create_nav_menu( 'Header Menu' );

		// The route's delete_item_permissions_check runs at dispatch and denies a
		// subscriber (no edit_theme_options) with its own rest_cannot_delete error.
		// The adapter is guard-only, so execute() surfaces that REAL error rather
		// than collapsing it to the generic ability_invalid_permissions.
		$result = wp_get_ability( 'og-menus/delete-classic-menu' )->execute(
			array( 'id' => $menu_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		// 403 because the subscriber is logged in (rest_authorization_required_code()).
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// Menu still exists — the denial blocked the delete.
		$this->assertInstanceOf( \WP_Term::class, get_term( $menu_id, 'nav_menu' ) );
	}
}
