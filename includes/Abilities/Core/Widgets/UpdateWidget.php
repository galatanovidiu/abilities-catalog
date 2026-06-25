<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-widgets/update-widget`.
 *
 * Wraps `POST /wp/v2/widgets/<id>` via `rest_do_request()` (the route registers
 * update under the EDITABLE methods) and returns the updated widget's id,
 * id_base, sidebar, and rendered HTML. The widget is identified by its instance
 * id (e.g. `block-3`); only the provided fields are forwarded, so an omitted
 * field leaves the widget unchanged. `instance`/`form_data` change the widget's
 * settings (the route's `save_widget`), and `sidebar` moves the widget to a
 * different widget area (the route calls `wp_assign_widget_to_sidebar` only when
 * the new sidebar differs).
 *
 * The `permission_callback` is a coarse, object-independent
 * `current_user_can('edit_theme_options')` gate — the same capability the
 * widgets controller's own `permissions_check()` enforces, so this ability is
 * never weaker than core. The wrapped route runs that check again under
 * `rest_do_request`, so a missing widget surfaces as the route's specific
 * `rest_widget_not_found` 404 instead of the Abilities API collapsing a
 * `permission_callback` failure into a generic permission error.
 *
 * @since 0.4.0
 */
final class UpdateWidget implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/update-widget';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Widget', 'abilities-catalog' ),
			'description'         => __( 'Updates a widget\'s settings and/or moves it to another sidebar (widget area), returning the widget\'s id, id_base, sidebar, and rendered HTML. Identify the widget by its instance id from og-widgets/list-widgets; discover sidebar ids with og-widgets/list-sidebars. Omitted fields are left unchanged. Pass settings as either instance (an object; for the core "block" widget use {raw:{content}} with Gutenberg block markup) or form_data (a URL-encoded string, for classic widgets). Pass sidebar to move the widget to that sidebar. Reversible by updating it again.', 'abilities-catalog' ),
			'category'            => 'widgets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __( 'The widget instance id to update, e.g. "block-3" or "text-2". Discover it with og-widgets/list-widgets.', 'abilities-catalog' ),
					),
					'instance'  => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'New widget settings. Shape is widget-specific; for the core "block" widget use {raw:{content:"<block markup>"}}. Provide instance OR form_data, not both. Omit to leave settings unchanged.', 'abilities-catalog' ),
					),
					'form_data' => array(
						'type'        => 'string',
						'description' => __( 'URL-encoded admin-form data, for classic widgets that do not accept a raw instance. Provide instance OR form_data, not both. Omit to leave settings unchanged.', 'abilities-catalog' ),
					),
					'sidebar'   => array(
						'type'        => 'string',
						'description' => __( 'Move the widget to this sidebar id (from og-widgets/list-sidebars). Use "wp_inactive_widgets" to deactivate it. Omit to leave the widget in its current sidebar.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'updated', 'id', 'id_base', 'sidebar' ),
				'properties'           => array(
					'updated'  => array(
						'type'        => 'boolean',
						'description' => __( 'Always true when the update succeeded.', 'abilities-catalog' ),
					),
					'id'       => array(
						'type'        => 'string',
						'description' => __( 'The widget instance id.', 'abilities-catalog' ),
					),
					'id_base'  => array(
						'type'        => 'string',
						'description' => __( 'The widget type slug (e.g. "block", "text").', 'abilities-catalog' ),
					),
					'sidebar'  => array(
						'type'        => 'string',
						'description' => __( 'The sidebar the widget now belongs to ("wp_inactive_widgets" when inactive).', 'abilities-catalog' ),
					),
					'rendered' => array(
						'type'        => 'string',
						'description' => __( 'The widget\'s front-end HTML output, or an empty string when the widget is inactive.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'widgets.php',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage widgets
	 * (`edit_theme_options`), the same capability the widgets controller enforces.
	 * The object-level outcome is deferred to the wrapped REST route
	 * (`rest_do_request` runs the route's own `permission_callback`), so a missing
	 * widget surfaces as `rest_widget_not_found` (404) rather than a generic
	 * permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage widgets.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated widget's id, id_base, sidebar, rendered HTML, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// The widget id contains no slash (`[\w\-]+`), but concatenate to mirror
		// the catalog's REST-dispatch idiom and keep the id out of query encoding.
		$request = new WP_REST_Request( 'POST', '/wp/v2/widgets/' . $id );

		// Forward only the fields the caller supplied so an omitted field leaves
		// the widget unchanged. The route gates `instance`/`form_data` on
		// `has_param()` and only moves the widget when `sidebar` is present and
		// differs, so key presence is the right intent signal.
		if ( isset( $input['instance'] ) && is_array( $input['instance'] ) ) {
			$request->set_param( 'instance', $input['instance'] );
		}
		if ( array_key_exists( 'form_data', $input ) ) {
			$request->set_param( 'form_data', (string) $input['form_data'] );
		}
		if ( array_key_exists( 'sidebar', $input ) ) {
			$request->set_param( 'sidebar', (string) $input['sidebar'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'updated'  => true,
			'id'       => (string) ( $data['id'] ?? $id ),
			'id_base'  => (string) ( $data['id_base'] ?? '' ),
			'sidebar'  => (string) ( $data['sidebar'] ?? '' ),
			'rendered' => (string) ( $data['rendered'] ?? '' ),
		);
	}
}
