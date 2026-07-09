<?php
/**
 * Integration tests for the og-widgets/list-sidebars ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;

/**
 * Exercises the sidebars list read end-to-end: the always-present
 * `wp_inactive_widgets` holding area must appear with status "inactive", the row
 * is the flat closed projection, and the wrapped route's `edit_theme_options`
 * check is the guard enforced on execute().
 */
final class ListSidebarsTest extends TestCase {

	/**
	 * Finds a shaped row by sidebar id in the result items.
	 *
	 * @param array<int,array<string,mixed>> $items The shaped result rows.
	 * @param string                         $id    The sidebar id to find.
	 * @return array<string,mixed>|null The matching row or null.
	 */
	private function rowById( array $items, string $id ): ?array {
		foreach ( $items as $row ) {
			if ( isset( $row['id'] ) && (string) $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-widgets/list-sidebars' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-widgets/list-sidebars', $ability->get_name() );
	}

	public function test_lists_inactive_holding_area(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/list-sidebars' )->execute();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'] );

		$row = $this->rowById( $result['items'], 'wp_inactive_widgets' );
		$this->assertNotNull( $row, 'The wp_inactive_widgets holding area must always appear.' );
		$this->assertSame( 'inactive', $row['status'] );
		$this->assertIsArray( $row['widgets'] );
	}

	public function test_row_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/list-sidebars' )->execute();
		$row    = $this->rowById( $result['items'], 'wp_inactive_widgets' );

		$this->assertNotNull( $row );

		$expected = array( 'id', 'name', 'description', 'status', 'widgets' );
		sort( $expected );
		$actual = array_keys( $row );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'Sidebar row must carry exactly the closed field set.' );
	}

	public function test_logged_out_user_is_denied(): void {
		// Adapter-backed: the route's own permission check runs at dispatch, so execute()
		// surfaces the REAL REST error — not the generic collapse. No test sidebar opts
		// into REST (show_in_rest defaults to false), so the sidebars controller denies a
		// caller without edit_theme_options via rest_cannot_manage_widgets. The catalog
		// imposes no require_permission floor, so this denial lives on the execute() path.
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-widgets/list-sidebars' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		// 401 because the user is logged out (rest_authorization_required_code()).
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_subscriber_is_denied(): void {
		// Same route denial as logged-out, but a logged-in subscriber lacks
		// edit_theme_options, so the controller returns 403 rather than 401.
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-widgets/list-sidebars' )->execute();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
