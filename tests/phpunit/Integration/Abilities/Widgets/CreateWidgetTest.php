<?php
/**
 * Integration tests for the widgets/create-widget ability.
 *
 * @package AbilitiesCatalog\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Tests\Integration\Abilities\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Exercises the create write ability end-to-end: a core "block" widget is added
 * via a raw instance into the wp_inactive_widgets holding area, the output shape
 * carries the additive `created` flag, the read-back confirms the side effect,
 * and the capability guard is enforced on execute().
 */
final class CreateWidgetTest extends TestCase {

	/**
	 * Widget instance ids created during a test, removed in tearDown.
	 *
	 * @var string[]
	 */
	private array $created_widget_ids = array();

	public function tear_down(): void {
		// Permanently remove every widget a test created so the shared global
		// widget state stays clean for the next test.
		foreach ( $this->created_widget_ids as $widget_id ) {
			$request = new WP_REST_Request( 'DELETE', '/wp/v2/widgets/' . $widget_id );
			$request->set_param( 'force', true );
			rest_do_request( $request );
		}
		$this->created_widget_ids = array();

		parent::tear_down();
	}

	public function test_ability_is_registered(): void {
		$ability = wp_get_ability( 'widgets/create-widget' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'widgets/create-widget', $ability->get_name() );
	}

	public function test_admin_can_create_block_widget(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/create-widget' )->execute(
			array(
				'id_base'  => 'block',
				'sidebar'  => 'wp_inactive_widgets',
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>W3 created</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 'block', $result['id_base'] );
		$this->assertNotSame( '', $result['id'] );
		$this->assertSame( 'wp_inactive_widgets', $result['sidebar'] );

		$this->created_widget_ids[] = $result['id'];

		// Read-back via the single-widget route confirms the side effect.
		$read = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $result['id'] );
		$read->set_param( 'context', 'edit' );
		$response = rest_do_request( $read );
		$this->assertFalse( $response->is_error() );
		$data = rest_get_server()->response_to_data( $response, false );
		$this->assertSame( $result['id'], $data['id'] );
		$this->assertSame( 'block', $data['id_base'] );
	}

	public function test_output_key_set_is_exact(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/create-widget' )->execute(
			array(
				'id_base'  => 'block',
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>Shape check</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertIsArray( $result );
		$this->created_widget_ids[] = $result['id'];

		$this->assertSame( array( 'created', 'id', 'id_base', 'sidebar', 'rendered' ), array_keys( $result ) );
		$this->assertIsBool( $result['created'] );
		$this->assertIsString( $result['id'] );
		$this->assertIsString( $result['id_base'] );
		$this->assertIsString( $result['sidebar'] );
		$this->assertIsString( $result['rendered'] );
	}

	public function test_invalid_id_base_surfaces_route_error_not_permission(): void {
		$this->actingAs( 'administrator' );

		$result = wp_get_ability( 'widgets/create-widget' )->execute(
			array(
				'id_base' => 'no_such_widget',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_widget', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertNotSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_user_is_denied_and_no_widget_created(): void {
		wp_set_current_user( 0 );

		$before = wp_get_sidebars_widgets()['wp_inactive_widgets'] ?? array();

		$result = wp_get_ability( 'widgets/create-widget' )->execute(
			array(
				'id_base'  => 'block',
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>Denied</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$after = wp_get_sidebars_widgets()['wp_inactive_widgets'] ?? array();
		$this->assertSame( $before, $after, 'No widget should be created when the caller is denied.' );
	}

	public function test_subscriber_is_denied_and_no_widget_created(): void {
		$this->actingAs( 'subscriber' );

		$before = wp_get_sidebars_widgets()['wp_inactive_widgets'] ?? array();

		$result = wp_get_ability( 'widgets/create-widget' )->execute(
			array(
				'id_base'  => 'block',
				'instance' => array( 'raw' => array( 'content' => '<!-- wp:paragraph --><p>Denied</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );

		$after = wp_get_sidebars_widgets()['wp_inactive_widgets'] ?? array();
		$this->assertSame( $before, $after, 'No widget should be created when the caller is denied.' );
	}
}
