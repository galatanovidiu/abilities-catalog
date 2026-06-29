<?php
/**
 * Integration tests for the og-widgets/update-widget ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises the update write ability end-to-end: a core "block" widget is
 * updated in place (settings and sidebar), the output shape is exact, a missing
 * widget surfaces the route's specific 404 (not a permission collapse), and the
 * route's own capability check denies logged-out and subscriber callers. The
 * ability is adapter-backed: denials surface through execute() as the route's
 * REAL error (rest_cannot_manage_widgets, 401/403), not the generic
 * ability_invalid_permissions collapse, and the widget is left unchanged.
 */
final class UpdateWidgetTest extends TestCase {

	/**
	 * Instance ids of widgets created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $created_widgets = array();

	/**
	 * Id of the active sidebar registered for the move test.
	 *
	 * @var string
	 */
	private string $test_sidebar_id = 'abilities-catalog-test-sidebar';

	/**
	 * Instance id of the widget under test.
	 *
	 * @var string
	 */
	private string $widget_id = '';

	public function set_up(): void {
		parent::set_up();

		register_sidebar(
			array(
				'id'   => $this->test_sidebar_id,
				'name' => 'Abilities Catalog Test Sidebar',
			)
		);

		$this->actingAs( 'administrator' );
		$this->widget_id = $this->createBlockWidget( '<!-- wp:paragraph --><p>W3 original</p><!-- /wp:paragraph -->' );
	}

	public function tear_down(): void {
		// Remove every widget the test created so the shared global state stays
		// clean for the next test.
		foreach ( $this->created_widgets as $widget_id ) {
			$request = new WP_REST_Request( 'DELETE', '/wp/v2/widgets/' . $widget_id );
			$request->set_param( 'force', true );
			rest_do_request( $request );
		}
		$this->created_widgets = array();

		unregister_sidebar( $this->test_sidebar_id );

		parent::tear_down();
	}

	/**
	 * Creates a core "block" widget with a raw content instance in the inactive
	 * area and records its id for cleanup.
	 *
	 * @param string $content Gutenberg block markup for the widget.
	 * @return string The created widget instance id.
	 */
	private function createBlockWidget( string $content ): string {
		$request = new WP_REST_Request( 'POST', '/wp/v2/widgets' );
		$request->set_param( 'id_base', 'block' );
		$request->set_param( 'sidebar', 'wp_inactive_widgets' );
		$request->set_param( 'instance', array( 'raw' => array( 'content' => $content ) ) );

		$response = rest_do_request( $request );
		$id       = (string) rest_get_server()->response_to_data( $response, false )['id'];

		$this->created_widgets[] = $id;

		return $id;
	}

	/**
	 * Reads back a widget's stored raw block content via the edit-context route.
	 *
	 * @param string $widget_id The widget instance id.
	 * @return string The stored block markup, or '' when unavailable.
	 */
	private function readWidgetContent( string $widget_id ): string {
		$request = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $widget_id );
		$request->set_param( 'context', 'edit' );

		$data = rest_get_server()->response_to_data( rest_do_request( $request ), false );

		return (string) ( $data['instance']['raw']['content'] ?? '' );
	}

	/**
	 * Reads back the sidebar a widget currently belongs to.
	 *
	 * @param string $widget_id The widget instance id.
	 * @return string The sidebar id, or '' when unavailable.
	 */
	private function readWidgetSidebar( string $widget_id ): string {
		$request = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $widget_id );

		$data = rest_get_server()->response_to_data( rest_do_request( $request ), false );

		return (string) ( $data['sidebar'] ?? '' );
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-widgets/update-widget' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-widgets/update-widget', $ability->get_name() );
	}

	public function test_admin_can_update_widget_content(): void {
		$result = wp_get_ability( 'og-widgets/update-widget' )->execute(
			array(
				'id'       => $this->widget_id,
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>W3 changed</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( $this->widget_id, $result['id'] );
		$this->assertSame( 'block', $result['id_base'] );
		$this->assertStringContainsString( 'W3 changed', $this->readWidgetContent( $this->widget_id ) );
	}

	public function test_admin_can_move_widget_to_another_sidebar(): void {
		$result = wp_get_ability( 'og-widgets/update-widget' )->execute(
			array(
				'id'      => $this->widget_id,
				'sidebar' => $this->test_sidebar_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( $this->test_sidebar_id, $result['sidebar'] );
		$this->assertSame( $this->test_sidebar_id, $this->readWidgetSidebar( $this->widget_id ) );
	}

	public function test_output_shape_is_exact(): void {
		$result = wp_get_ability( 'og-widgets/update-widget' )->execute(
			array(
				'id'      => $this->widget_id,
				'sidebar' => $this->test_sidebar_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'updated', 'id', 'id_base', 'sidebar', 'rendered' ),
			array_keys( $result )
		);
		$this->assertIsBool( $result['updated'] );
		$this->assertIsString( $result['id'] );
		$this->assertIsString( $result['id_base'] );
		$this->assertIsString( $result['sidebar'] );
		$this->assertIsString( $result['rendered'] );
	}

	/**
	 * A missing widget must surface the route's specific `rest_widget_not_found`
	 * 404, not the generic permission collapse. `block-99999` has a valid
	 * `block` id_base, so the route resolves the type and returns 404 for the
	 * unknown instance.
	 */
	public function test_missing_widget_returns_404_not_generic(): void {
		$result = wp_get_ability( 'og-widgets/update-widget' )->execute(
			array(
				'id'      => 'block-99999',
				'sidebar' => $this->test_sidebar_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_widget_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A logged-out caller is denied by the wrapped route. Adapter-backed: the
	 * route's own permission check runs at dispatch, so execute() surfaces the
	 * REAL error (rest_cannot_manage_widgets, 401 — logged-out maps to
	 * rest_authorization_required_code()), not the generic
	 * ability_invalid_permissions collapse, and the widget is unchanged.
	 */
	public function test_logged_out_user_is_denied_and_widget_unchanged(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-widgets/update-widget' )->execute(
			array(
				'id'       => $this->widget_id,
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>denied</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		$this->actingAs( 'administrator' );
		$this->assertStringContainsString( 'W3 original', $this->readWidgetContent( $this->widget_id ) );
	}

	/**
	 * A subscriber is denied by the wrapped route. Adapter-backed: execute()
	 * surfaces the route's REAL error (rest_cannot_manage_widgets, 403 — a
	 * logged-in user lacking edit_theme_options maps to
	 * rest_authorization_required_code()), not the generic collapse, and the
	 * widget is unchanged.
	 */
	public function test_subscriber_is_denied_and_widget_unchanged(): void {
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-widgets/update-widget' )->execute(
			array(
				'id'       => $this->widget_id,
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>denied</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		$this->actingAs( 'administrator' );
		$this->assertStringContainsString( 'W3 original', $this->readWidgetContent( $this->widget_id ) );
	}
}
