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
 * Read ability: `widgets/list-widgets`.
 *
 * Wraps `GET /wp/v2/widgets` via `rest_do_request()` and returns the widget
 * instances as flat rows, optionally filtered to one sidebar. The widgets
 * collection route returns a bare JSON array (no `X-WP-Total` header), so the
 * total is the row count. Each row is projected to the shared widget shape
 * (`id`, `id_base`, `sidebar`, `rendered`), dropping `rendered_form` and the
 * `instance` object, which are admin-form noise for an agent.
 *
 * @since 0.1.0
 */
final class ListWidgets implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'widgets/list-widgets';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Widgets', 'abilities-catalog' ),
			'description'         => __( 'Lists widget instances, optionally filtered to one sidebar, returning each widget\'s id, id_base (type), sidebar, and rendered HTML. Use this to find the widget id needed by widgets/get-widget, widgets/update-widget, or widgets/delete-widget. Discover sidebar ids with widgets/list-sidebars; an empty or unknown sidebar returns no items.', 'abilities-catalog' ),
			'category'            => 'widgets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'sidebar' => array(
						'type'        => 'string',
						'description' => __( 'Limit results to widgets in this sidebar id (e.g. "sidebar-1" or "wp_inactive_widgets"). Discover sidebar ids with widgets/list-sidebars. Omit to list widgets across all sidebars.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items', 'total' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => self::widgetItemSchema(),
						'description' => __( 'The list of widget instances as flat rows. Use widgets/get-widget for a single widget.', 'abilities-catalog' ),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Number of widget instances returned (the count of items).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Closed schema for one projected widget row.
	 *
	 * Kept beside the projection in execute() so the declared shape and the
	 * runtime row cannot drift. `rendered` is empty for inactive widgets, so it
	 * is not required.
	 *
	 * @return array<string,mixed> The widget item schema.
	 */
	private static function widgetItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'id_base', 'sidebar' ),
			'properties'           => array(
				'id'       => array(
					'type'        => 'string',
					'description' => __( 'The widget instance id (e.g. "block-3" or "text-2"). Pass it to widgets/get-widget, widgets/update-widget, or widgets/delete-widget.', 'abilities-catalog' ),
				),
				'id_base'  => array(
					'type'        => 'string',
					'description' => __( 'The widget type slug (e.g. "block", "text"). Corresponds to the id from widgets/list-widget-types.', 'abilities-catalog' ),
				),
				'sidebar'  => array(
					'type'        => 'string',
					'description' => __( 'The sidebar id the widget sits in ("wp_inactive_widgets" when deactivated).', 'abilities-catalog' ),
				),
				'rendered' => array(
					'type'        => 'string',
					'description' => __( 'The widget\'s front-end HTML output; empty for an inactive widget.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Permission check: baseline `edit_theme_options` to list widgets.
	 *
	 * Coarse, object-independent gate matching the widgets REST routes, which
	 * all check `edit_theme_options` in `permissions_check()`. The wrapped route
	 * runs its own permission check under `rest_do_request`, so this is not
	 * weaker than core; an unknown `sidebar` simply yields no rows rather than a
	 * permission collapse.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list widgets.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and total, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/widgets' );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		if ( isset( $input['sidebar'] ) ) {
			$request->set_param( 'sidebar', (string) $input['sidebar'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$rows = is_array( $data ) ? array_map( array( self::class, 'widgetRow' ), $data ) : array();

		return array(
			'items' => array_values( $rows ),
			'total' => count( $rows ),
		);
	}

	/**
	 * Projects one REST widget object to the flat closed row.
	 *
	 * @param array<string,mixed> $widget The raw REST widget object.
	 * @return array<string,mixed> The projected widget row.
	 */
	private static function widgetRow( array $widget ): array {
		return array(
			'id'       => (string) ( $widget['id'] ?? '' ),
			'id_base'  => (string) ( $widget['id_base'] ?? '' ),
			'sidebar'  => (string) ( $widget['sidebar'] ?? '' ),
			'rendered' => (string) ( $widget['rendered'] ?? '' ),
		);
	}
}
