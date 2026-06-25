<?php
/**
 * Integration tests for the og-widgets/list-widgets ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises the list read end-to-end: real widgets in, shaped flat rows plus a
 * count-based total out, with the capability guard enforced by the Abilities
 * API on execute(). Targets the always-present `wp_inactive_widgets` holding
 * area (the wp-env block theme registers no classic sidebars) and a registered
 * test sidebar so the `sidebar` filter is exercised against an active area.
 */
final class ListWidgetsTest extends TestCase {

	/**
	 * The id of the test sidebar registered in set_up().
	 *
	 * @var string
	 */
	private string $test_sidebar = 'abilities-catalog-test-sidebar';

	/**
	 * Widget instance ids created during the test, removed in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $created_widget_ids = array();

	public function set_up(): void {
		parent::set_up();

		register_sidebar(
			array(
				'id'   => $this->test_sidebar,
				'name' => 'Abilities Catalog Test Sidebar',
			)
		);
	}

	public function tear_down(): void {
		$this->actingAs( 'administrator' );

		foreach ( $this->created_widget_ids as $widget_id ) {
			$request = new WP_REST_Request( 'DELETE', '/wp/v2/widgets/' . $widget_id );
			$request->set_param( 'force', true );
			rest_do_request( $request );
		}

		unregister_sidebar( $this->test_sidebar );

		parent::tear_down();
	}

	/**
	 * Creates a core "block" widget in the given sidebar and returns its id.
	 *
	 * @param string $sidebar The target sidebar id.
	 * @return string The created widget instance id.
	 */
	private function createBlockWidget( string $sidebar ): string {
		$request = new WP_REST_Request( 'POST', '/wp/v2/widgets' );
		$request->set_param( 'id_base', 'block' );
		$request->set_param( 'sidebar', $sidebar );
		$request->set_param(
			'instance',
			array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>W3 test</p><!-- /wp:paragraph -->' ) )
		);

		$response = rest_do_request( $request );
		$this->assertFalse( $response->is_error(), 'The block widget fixture must create successfully.' );

		$id                         = rest_get_server()->response_to_data( $response, false )['id'];
		$this->created_widget_ids[] = $id;

		return $id;
	}

	/**
	 * Finds a shaped row by widget id in the result items.
	 *
	 * @param array<int,array<string,mixed>> $items The shaped result rows.
	 * @param string                         $id    The widget id to find.
	 * @return array<string,mixed>|null The matching row or null.
	 */
	private function rowById( array $items, string $id ): ?array {
		foreach ( $items as $row ) {
			if ( isset( $row['id'] ) && $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-widgets/list-widgets' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-widgets/list-widgets', $ability->get_name() );
	}

	public function test_admin_lists_widget_with_correct_id_base(): void {
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget( 'wp_inactive_widgets' );

		$result = wp_get_ability( 'og-widgets/list-widgets' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertIsArray( $result['items'] );
		$this->assertIsInt( $result['total'] );
		$this->assertSame( count( $result['items'] ), $result['total'], 'total must equal the item count.' );

		$row = $this->rowById( $result['items'], $widget_id );
		$this->assertNotNull( $row, 'The created block widget must appear in the list.' );
		$this->assertSame( 'block', $row['id_base'] );
		$this->assertSame( 'wp_inactive_widgets', $row['sidebar'] );
	}

	public function test_sidebar_filter_narrows_results(): void {
		$this->actingAs( 'administrator' );
		$inactive_id = $this->createBlockWidget( 'wp_inactive_widgets' );
		$active_id   = $this->createBlockWidget( $this->test_sidebar );

		$result = wp_get_ability( 'og-widgets/list-widgets' )->execute( array( 'sidebar' => $this->test_sidebar ) );

		$this->assertNotNull(
			$this->rowById( $result['items'], $active_id ),
			'A widget in the filtered sidebar must appear.'
		);
		$this->assertNull(
			$this->rowById( $result['items'], $inactive_id ),
			'A widget in another sidebar must be excluded by the filter.'
		);
		foreach ( $result['items'] as $row ) {
			$this->assertSame( $this->test_sidebar, $row['sidebar'], 'Every filtered row must be in the requested sidebar.' );
		}
	}

	public function test_unknown_sidebar_returns_empty_result(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/list-widgets' )->execute( array( 'sidebar' => 'no-such-sidebar' ) );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['total'] );
	}

	public function test_row_has_exactly_the_closed_field_set(): void {
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget( 'wp_inactive_widgets' );

		$result = wp_get_ability( 'og-widgets/list-widgets' )->execute( array() );
		$row    = $this->rowById( $result['items'], $widget_id );

		$this->assertNotNull( $row );

		$expected = array( 'id', 'id_base', 'sidebar', 'rendered' );
		sort( $expected );
		$actual = array_keys( $row );
		sort( $actual );

		$this->assertSame( $expected, $actual, 'A row must carry exactly the closed widget field set.' );
		$this->assertIsString( $row['id'] );
		$this->assertIsString( $row['id_base'] );
		$this->assertIsString( $row['sidebar'] );
		$this->assertIsString( $row['rendered'] );
	}

	public function test_logged_out_user_is_denied(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-widgets/list-widgets' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_subscriber_is_denied(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-widgets/list-widgets' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
