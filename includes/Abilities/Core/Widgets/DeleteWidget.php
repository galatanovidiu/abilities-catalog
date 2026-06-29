<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `og-widgets/delete-widget`.
 *
 * Wraps `DELETE /wp/v2/widgets/<id>` via the Abilities REST Adapter. The `force`
 * param selects between two route behaviours (verified against
 * `WP_REST_Widgets_Controller::delete_item()`):
 *
 *   - `force=false` (default): the route reassigns the widget to the special
 *     `wp_inactive_widgets` holding area and returns the widget object. The widget
 *     is deactivated, not destroyed, and can be moved back — so the result reports
 *     `deleted=false` with `sidebar='wp_inactive_widgets'`.
 *   - `force=true`: the route runs the widget's delete callback, unassigns it, and
 *     returns `{ deleted:true, previous:{...} }`. This permanently removes the widget.
 *
 * The input schema is OVERRIDDEN so the catalog exposes only `id` (path capture) and
 * `force` (default `false`), closed to anything else. The output is OVERRIDDEN to the
 * catalog's flat field set through {@see shapeOutput()}, which folds both route shapes
 * into `{ deleted, id, id_base, sidebar }` from the response body alone — the
 * `force=true` `previous` object comes from the response, so no pre-dispatch state is
 * needed.
 *
 * Classified `destructive:true` by the worst case (the `force=true` path is
 * irreversible) and `idempotent:false`. It is NOT `dangerous`: it is gated on
 * `edit_theme_options`, its blast radius is front-end widget appearance (not site
 * integrity), and the default path is reversible.
 *
 * Permission delegates to the route's own `delete_item_permissions_check`
 * (`edit_theme_options`) — the exact cap the catalog baseline used, so no
 * `require_permission` floor is set. A missing widget surfaces as the route's
 * `rest_widget_not_found` 404, and a denied caller as `rest_cannot_manage_widgets`
 * (401/403), rather than the Abilities API collapsing either into a generic
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/widgets/(?P<id>[\w\-]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Widget', 'abilities-catalog' ),
				'description'     => __( 'Removes a widget. By default (force=false) it is deactivated — moved to the Inactive Widgets area and recoverable. Pass force=true to permanently delete it; that cannot be undone. Discover the widget id with og-widgets/list-widgets.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'widgets.php',
				),
			)
		);
	}

	/**
	 * Folds both delete-route shapes into the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST delete response body. The `force=true` shape is the
	 * `{ deleted:true, previous:{...} }` envelope; the `force=false` shape is the
	 * widget object itself (now in `wp_inactive_widgets`). Everything the result needs
	 * is in `$data` — the `previous` object comes from the response, not from
	 * pre-dispatch state — so `$input` is read only to default the `id` and `$response`
	 * is unused.
	 *
	 * @param mixed               $data     The REST delete response body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The deleted flag and widget identity.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		// force=true => { deleted:true, previous:{ id, id_base, sidebar, ... } };
		// force=false => the widget object itself (now in wp_inactive_widgets).
		$deleted = (bool) ( $data['deleted'] ?? false );
		$widget  = $deleted && is_array( $data['previous'] ?? null ) ? $data['previous'] : $data;

		return array(
			'deleted' => $deleted,
			'id'      => (string) ( $widget['id'] ?? ( $input['id'] ?? '' ) ),
			'id_base' => (string) ( $widget['id_base'] ?? '' ),
			'sidebar' => (string) ( $widget['sidebar'] ?? '' ),
		);
	}
}
