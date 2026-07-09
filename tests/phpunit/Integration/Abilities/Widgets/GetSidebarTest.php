<?php
/**
 * Integration tests for the og-widgets/get-sidebar ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single-object sidebar read end-to-end: a real sidebar id in, a
 * flat shaped projection out. Permission delegates to the wrapped route, whose
 * own denial (and its specific 404 for an unknown id) surfaces through execute()
 * as the real REST error, never collapsed to a generic permission error.
 */
final class GetSidebarTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-widgets/get-sidebar' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-widgets/get-sidebar', $ability->get_name() );
	}

	public function test_admin_can_read_inactive_holding_sidebar(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'wp_inactive_widgets', $result['id'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertIsArray( $result['widgets'] );
	}

	public function test_output_has_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'name', 'description', 'status', 'widgets' ),
			array_keys( $result )
		);
	}

	public function test_unknown_sidebar_returns_route_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/get-sidebar' )->execute( array( 'id' => 'no-such-sidebar' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_sidebar_not_found', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 404, $data['status'] );
	}

	public function test_logged_out_user_is_denied_with_real_route_error(): void {
		// Adapter-backed: the route's own permission check (edit_theme_options) runs at
		// dispatch, so execute() surfaces the route's REAL error — not the generic
		// collapse. This ability sets no require_permission guard, so the permission
		// phase returns true and the denial lives on the execute() path.
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		// 401 because the user is logged out (rest_authorization_required_code()).
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_subscriber_is_denied_with_real_route_error(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		// 403 because the user is logged in but lacks edit_theme_options.
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
