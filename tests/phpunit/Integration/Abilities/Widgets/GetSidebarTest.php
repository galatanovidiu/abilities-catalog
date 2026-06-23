<?php
/**
 * Integration tests for the widgets/get-sidebar ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the single-object sidebar read end-to-end: a real sidebar id in, a
 * flat shaped projection out, with the capability guard enforced on execute()
 * and the route's specific 404 surfaced (never collapsed to permission) for an
 * unknown id.
 */
final class GetSidebarTest extends TestCase {

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'widgets/get-sidebar' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'widgets/get-sidebar', $ability->get_name() );
	}

	public function test_admin_can_read_inactive_holding_sidebar(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'wp_inactive_widgets', $result['id'] );
		$this->assertSame( 'inactive', $result['status'] );
		$this->assertIsArray( $result['widgets'] );
	}

	public function test_output_has_exact_key_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'id', 'name', 'description', 'status', 'widgets' ),
			array_keys( $result )
		);
	}

	public function test_unknown_sidebar_returns_route_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/get-sidebar' )->execute( array( 'id' => 'no-such-sidebar' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_sidebar_not_found', $result->get_error_code() );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 404, $data['status'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'widgets/get-sidebar' )->execute( array( 'id' => 'wp_inactive_widgets' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
