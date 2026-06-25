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
 * Destructive write ability: `og-widgets/delete-widget`.
 *
 * Wraps `DELETE /wp/v2/widgets/<id>` via `rest_do_request()`. The `force` param
 * selects between two route behaviours (verified against
 * `WP_REST_Widgets_Controller::delete_item()`):
 *
 *   - `force=false` (default): the route reassigns the widget to the special
 *     `wp_inactive_widgets` holding area and returns the widget object. The widget
 *     is deactivated, not destroyed, and can be moved back — so the result reports
 *     `deleted=false` with `sidebar='wp_inactive_widgets'`.
 *   - `force=true`: the route runs the widget's delete callback, unassigns it, and
 *     returns `{ deleted:true, previous:{...} }`. This permanently removes the widget.
 *
 * Classified `destructive:true` by the worst case (the `force=true` path is
 * irreversible). It is NOT `dangerous`: it is gated on `edit_theme_options`, its
 * blast radius is front-end widget appearance (not site integrity), and the default
 * path is reversible.
 *
 * The `permission_callback` is a coarse `edit_theme_options` gate — the catalog
 * baseline for the widgets cluster and the exact cap the route's
 * `delete_item_permissions_check` enforces. The wrapped route owns object existence,
 * so a missing widget surfaces as the route's `rest_widget_not_found` 404 (via
 * `RestError::from()`) rather than the Abilities API collapsing it into a generic
 * permission failure.
 *
 * @since 0.5.0
 */
final class DeleteWidget implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/delete-widget';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Widget', 'abilities-catalog' ),
			'description'         => __( 'Removes a widget. By default (force=false) it is deactivated — moved to the Inactive Widgets area and recoverable. Pass force=true to permanently delete it; that cannot be undone. Discover the widget id with og-widgets/list-widgets.', 'abilities-catalog' ),
			'category'            => 'og-core-widgets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'string',
						'description' => __( 'The widget instance id to remove (e.g. "block-3"). Discover it with og-widgets/list-widgets.', 'abilities-catalog' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When false (default), the widget is deactivated — moved to the Inactive Widgets area (wp_inactive_widgets) and recoverable. When true, the widget is permanently deleted; this cannot be undone.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'True only when force=true permanently removed the widget. False when force=false deactivated it (moved to wp_inactive_widgets).', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'string',
						'description' => __( 'The widget instance id that was removed or deactivated.', 'abilities-catalog' ),
					),
					'id_base' => array(
						'type'        => 'string',
						'description' => __( 'The widget type slug (e.g. "block", "text").', 'abilities-catalog' ),
					),
					'sidebar' => array(
						'type'        => 'string',
						'description' => __( 'The sidebar the widget was in. When force=false this is "wp_inactive_widgets" (where the widget now sits); when force=true it is the sidebar it was removed from.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'widgets.php',
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage widgets
	 * (`edit_theme_options`). This is the catalog baseline for the widgets cluster
	 * and the exact cap the wrapped route's `delete_item_permissions_check` enforces,
	 * so it is never weaker than core. Object existence is deferred to the route, so a
	 * missing widget surfaces as `rest_widget_not_found` 404 via `RestError::from()`
	 * instead of a generic permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage widgets.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Handles both route response shapes: the `force=true` `{ deleted, previous }`
	 * envelope and the `force=false` widget object (now in `wp_inactive_widgets`).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and widget identity, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );
		$force = (bool) ( $input['force'] ?? false );

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/widgets/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		// force=true => { deleted:true, previous:{ id, id_base, sidebar, ... } };
		// force=false => the widget object itself (now in wp_inactive_widgets).
		$deleted = (bool) ( $data['deleted'] ?? false );
		$widget  = $deleted && is_array( $data['previous'] ?? null ) ? $data['previous'] : $data;
		$widget  = is_array( $widget ) ? $widget : array();

		return array(
			'deleted' => $deleted,
			'id'      => (string) ( $widget['id'] ?? $id ),
			'id_base' => (string) ( $widget['id_base'] ?? '' ),
			'sidebar' => (string) ( $widget['sidebar'] ?? '' ),
		);
	}
}
