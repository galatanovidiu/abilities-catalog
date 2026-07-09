<?php
/**
 * Integration tests for the og-menus/delete-navigation ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the T2 destructive write ability end-to-end: trashes (default) or
 * permanently deletes a block navigation menu, with the snapshot output shape
 * (title, status). Adapter-backed: permission delegates to the wrapped route, so a
 * denial surfaces through execute() as the route's REAL REST error (not the generic
 * ability_invalid_permissions collapse), and the missing-id 404 reaches the caller.
 */
final class DeleteNavigationTest extends TestCase {

	/**
	 * Creates a wp_navigation post and returns its ID.
	 *
	 * @param string $title The navigation menu title.
	 * @return int The new navigation post ID.
	 */
	private function createNavigation( string $title ): int {
		return (int) wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '',
			)
		);
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-menus/delete-navigation' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-menus/delete-navigation', $ability->get_name() );
	}

	public function test_admin_trashes_navigation_by_default(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->createNavigation( 'Primary Navigation' );

		$result = wp_get_ability( 'og-menus/delete-navigation' )->execute(
			array( 'id' => $nav_id )
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertTrue( $result['trashed'] );
		$this->assertSame( $nav_id, $result['id'] );

		// The post still exists but is in Trash (recoverable).
		$this->assertSame( 'trash', get_post_status( $nav_id ) );
	}

	public function test_force_permanently_deletes_navigation(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->createNavigation( 'Footer Navigation' );

		$result = wp_get_ability( 'og-menus/delete-navigation' )->execute(
			array(
				'id'    => $nav_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertFalse( $result['trashed'] );
		$this->assertSame( $nav_id, $result['id'] );

		// The post is gone permanently.
		$this->assertNull( get_post( $nav_id ) );
	}

	public function test_output_reports_title_on_trash(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->createNavigation( 'Header Navigation' );

		$result = wp_get_ability( 'og-menus/delete-navigation' )->execute(
			array( 'id' => $nav_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Header Navigation', $result['title'] );
		$this->assertSame( 'trash', $result['status'] );
	}

	public function test_output_reports_title_on_force_delete(): void {
		$this->actingAs( 'administrator' );
		$nav_id = $this->createNavigation( 'Sidebar Navigation' );

		$result = wp_get_ability( 'og-menus/delete-navigation' )->execute(
			array(
				'id'    => $nav_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Sidebar Navigation', $result['title'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_missing_navigation_id_surfaces_route_404_not_generic(): void {
		$this->actingAs( 'administrator' );

		// Adapter-backed: the ability has no require_permission guard, so the permission
		// phase returns true and the wrapped route runs at dispatch. An admin holds
		// edit_theme_options, so a non-existent id reaches the route and surfaces its
		// specific 404 through execute() — not the opaque ability_invalid_permissions.
		$result = wp_get_ability( 'og-menus/delete-navigation' )->execute(
			array( 'id' => 999999 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_subscriber_is_denied(): void {
		// The route enforces its own delete_item_permissions_check (delete_post →
		// edit_theme_options) at dispatch. This ability sets no require_permission floor,
		// so the permission phase returns true and execute() surfaces the route's REAL
		// error — rest_cannot_delete (403) — not the generic ability_invalid_permissions.
		$this->actingAs( 'subscriber' );
		$nav_id = $this->createNavigation( 'Denied Navigation' );

		$result = wp_get_ability( 'og-menus/delete-navigation' )->execute(
			array( 'id' => $nav_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_delete', $result->get_error_code() );
		// 403 because the subscriber is logged in (rest_authorization_required_code()).
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
