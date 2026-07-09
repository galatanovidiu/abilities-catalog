<?php
/**
 * Integration tests for the og-widgets/get-widget ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises og-widgets/get-widget end-to-end: a real core "block" widget in, the
 * shared flat widget row out, with the capability guard and the route's
 * specific 404 enforced on execute().
 */
final class GetWidgetTest extends TestCase {

	/**
	 * Widget instance ids created during the test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $created_widget_ids = array();

	/**
	 * The block widget created in set_up.
	 *
	 * @var string
	 */
	private string $widget_id;

	public function set_up(): void {
		parent::set_up();

		$this->actingAs( 'administrator' );
		$this->widget_id = $this->createBlockWidget();
	}

	public function tear_down(): void {
		foreach ( $this->created_widget_ids as $widget_id ) {
			$request = new WP_REST_Request( 'DELETE', '/wp/v2/widgets/' . $widget_id );
			$request->set_param( 'force', true );
			rest_do_request( $request );
		}
		$this->created_widget_ids = array();

		parent::tear_down();
	}

	/**
	 * Creates a core "block" widget (supports a raw instance) in
	 * wp_inactive_widgets and returns its id.
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
		$this->assertFalse( $response->is_error(), 'Failed to create the test block widget.' );

		$id = rest_get_server()->response_to_data( $response, false )['id'];

		$this->created_widget_ids[] = $id;

		return $id;
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'og-widgets/get-widget' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'og-widgets/get-widget', $ability->get_name() );
	}

	public function test_admin_gets_widget_by_id(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/get-widget' )->execute( array( 'id' => $this->widget_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->widget_id, $result['id'] );
		$this->assertSame( 'block', $result['id_base'] );
		$this->assertSame( 'wp_inactive_widgets', $result['sidebar'] );
	}

	public function test_output_key_set_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/get-widget' )->execute( array( 'id' => $this->widget_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'id', 'id_base', 'sidebar', 'rendered' ), array_keys( $result ) );
		$this->assertIsString( $result['id'] );
		$this->assertIsString( $result['id_base'] );
		$this->assertIsString( $result['sidebar'] );
		$this->assertIsString( $result['rendered'] );
	}

	public function test_missing_widget_returns_route_404_not_permission_collapse(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'og-widgets/get-widget' )->execute( array( 'id' => 'block-99999' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_widget_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied(): void {
		// Adapter-backed: permission delegates to the wrapped route, which runs at
		// dispatch, so execute() surfaces the route's REAL error — not the generic
		// collapse. The test widget sits in wp_inactive_widgets (not publicly readable),
		// so the route requires edit_theme_options and denies an anonymous read with
		// rest_cannot_manage_widgets. 401 because the user is logged out
		// (rest_authorization_required_code()). check_permissions() is guard-only now and
		// this ability has no require_permission floor, so the denial lives on execute().
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'og-widgets/get-widget' )->execute( array( 'id' => $this->widget_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		$this->assertSame( 401, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_subscriber_is_denied(): void {
		// A logged-in subscriber lacks edit_theme_options, so the route denies the read
		// with the same rest_cannot_manage_widgets via execute(). 403 because the user is
		// authenticated (rest_authorization_required_code()).
		$this->actingAs( 'subscriber' );

		$result = wp_get_ability( 'og-widgets/get-widget' )->execute( array( 'id' => $this->widget_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code(), 'real route error, not the generic collapse' );
		$this->assertSame( 'rest_cannot_manage_widgets', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}
}
