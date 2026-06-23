<?php
/**
 * Integration tests for the widgets/delete-widget ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises both delete branches end-to-end: force=false deactivates a widget into
 * wp_inactive_widgets (recoverable), force=true permanently removes it. Also proves
 * the route's 404 is surfaced (not a permission collapse) and that denied callers
 * leave the widget intact.
 */
final class DeleteWidgetTest extends TestCase {

	/**
	 * Widget instance ids created during a test, for tearDown cleanup.
	 *
	 * @var array<int,string>
	 */
	private array $created_widgets = array();

	public function tear_down(): void {
		foreach ( $this->created_widgets as $widget_id ) {
			$request = new WP_REST_Request( 'DELETE', '/wp/v2/widgets/' . $widget_id );
			$request->set_param( 'force', true );
			rest_do_request( $request );
		}
		$this->created_widgets = array();

		parent::tear_down();
	}

	/**
	 * Creates a core "block" widget (supports a raw instance) in wp_inactive_widgets
	 * and returns its instance id. Assumes the current user can manage widgets.
	 *
	 * @return string The created widget instance id.
	 */
	private function createBlockWidget(): string {
		$request = new WP_REST_Request( 'POST', '/wp/v2/widgets' );
		$request->set_param( 'id_base', 'block' );
		$request->set_param( 'sidebar', 'wp_inactive_widgets' );
		$request->set_param(
			'instance',
			array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>W3 test</p><!-- /wp:paragraph -->' ) )
		);

		$response = rest_do_request( $request );
		$data     = rest_get_server()->response_to_data( $response, false );
		$id       = (string) ( $data['id'] ?? '' );

		$this->assertNotSame( '', $id, 'Failed to create the test block widget.' );
		$this->created_widgets[] = $id;

		return $id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'widgets/delete-widget' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'widgets/delete-widget', $ability->get_name() );
	}

	public function test_force_false_deactivates_widget_into_inactive_area(): void {
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget();

		$result = wp_get_ability( 'widgets/delete-widget' )->execute(
			array(
				'id'    => $widget_id,
				'force' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['deleted'] );
		$this->assertSame( $widget_id, $result['id'] );
		$this->assertSame( 'block', $result['id_base'] );
		$this->assertSame( 'wp_inactive_widgets', $result['sidebar'] );

		// Read-back: the widget still exists and now sits in wp_inactive_widgets.
		$get_request = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $widget_id );
		$get_request->set_param( 'context', 'edit' );
		$get_response = rest_do_request( $get_request );
		$this->assertFalse( $get_response->is_error() );
		$get_data = rest_get_server()->response_to_data( $get_response, false );
		$this->assertSame( 'wp_inactive_widgets', $get_data['sidebar'] );
	}

	public function test_force_true_permanently_deletes_widget(): void {
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget();

		$result = wp_get_ability( 'widgets/delete-widget' )->execute(
			array(
				'id'    => $widget_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertSame( $widget_id, $result['id'] );
		$this->assertSame( 'block', $result['id_base'] );

		// Already gone — drop from cleanup so tearDown does not re-delete.
		$this->created_widgets = array_values(
			array_diff( $this->created_widgets, array( $widget_id ) )
		);

		// Read-back: the widget no longer exists.
		$get_request  = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $widget_id );
		$get_response = rest_do_request( $get_request );
		$this->assertTrue( $get_response->is_error() );
		$this->assertSame( 'rest_widget_not_found', $get_response->as_error()->get_error_code() );
	}

	public function test_output_key_set_is_exact(): void {
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget();

		$result = wp_get_ability( 'widgets/delete-widget' )->execute(
			array(
				'id'    => $widget_id,
				'force' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'deleted', 'id', 'id_base', 'sidebar' ), array_keys( $result ) );
		$this->assertIsBool( $result['deleted'] );
		$this->assertIsString( $result['id'] );
		$this->assertIsString( $result['id_base'] );
		$this->assertIsString( $result['sidebar'] );
	}

	public function test_missing_widget_returns_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/delete-widget' )->execute(
			array(
				'id'    => 'block-99999',
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_widget_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_widget_survives(): void {
		// Create the widget as an administrator, then drop privileges.
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget();
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'widgets/delete-widget' )->execute(
			array(
				'id'    => $widget_id,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The widget still exists (verify back as an administrator).
		$this->actingAs( 'administrator' );
		$get_request  = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $widget_id );
		$get_response = rest_do_request( $get_request );
		$this->assertFalse( $get_response->is_error() );
	}

	public function test_subscriber_is_denied_and_widget_survives(): void {
		$this->actingAs( 'administrator' );
		$widget_id = $this->createBlockWidget();
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'widgets/delete-widget' )->execute(
			array(
				'id'    => $widget_id,
				'force' => true,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		// The widget still exists (verify back as an administrator).
		$this->actingAs( 'administrator' );
		$get_request  = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $widget_id );
		$get_response = rest_do_request( $get_request );
		$this->assertFalse( $get_response->is_error() );
	}
}
