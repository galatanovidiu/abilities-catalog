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
 * Read ability: `og-widgets/list-widgets`.
 *
 * Wraps `GET /wp/v2/widgets` via the Abilities REST Adapter. The widgets
 * collection route returns a list, so the adapter wraps it as
 * `{ items, total, total_pages }`; {@see shapeOutput()} then reshapes that to the
 * catalog's `{ items, total }`, projecting each row to the closed widget shape
 * (`id`, `id_base`, `sidebar`, `rendered`) and dropping `rendered_form` and the
 * `instance` object, which are admin-form noise for an agent. The input schema is
 * OVERRIDDEN to the catalog's narrow two-knob shape (`sidebar`, `context`) instead
 * of the route's full query-arg surface; both pass through to the route. Permission
 * delegates to the route's own check (no `require_permission` floor), which reads
 * any sidebar marked `show_in_rest` and otherwise requires `edit_theme_options`.
 *
 * @since 0.1.0
 */
final class ListWidgets implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/list-widgets';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/widgets',
				'method'          => 'GET',
				'label'           => __( 'List Widgets', 'abilities-catalog' ),
				'description'     => __( 'Lists widget instances, optionally filtered to one sidebar, returning each widget\'s id, id_base (type), sidebar, and rendered HTML. Use this to find the widget id needed by og-widgets/get-widget, og-widgets/update-widget, or og-widgets/delete-widget. Discover sidebar ids with og-widgets/list-sidebars; an empty or unknown sidebar returns no items.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'sidebar' => array(
							'type'        => 'string',
							'description' => __( 'Limit results to widgets in this sidebar id (e.g. "sidebar-1" or "wp_inactive_widgets"). Discover sidebar ids with og-widgets/list-sidebars. Omit to list widgets across all sidebars.', 'abilities-catalog' ),
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
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'total' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'items'       => self::widgetItemSchema(),
							'description' => __( 'The list of widget instances as flat rows. Use og-widgets/get-widget for a single widget.', 'abilities-catalog' ),
						),
						'total' => array(
							'type'        => 'integer',
							'description' => __( 'Number of widget instances returned (the count of items).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Closed schema for one projected widget row.
	 *
	 * Kept beside the projection in {@see shapeOutput()} so the declared shape and
	 * the runtime row cannot drift. `rendered` is empty for inactive widgets, so it
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
					'description' => __( 'The widget instance id (e.g. "block-3" or "text-2"). Pass it to og-widgets/get-widget, og-widgets/update-widget, or og-widgets/delete-widget.', 'abilities-catalog' ),
				),
				'id_base'  => array(
					'type'        => 'string',
					'description' => __( 'The widget type slug (e.g. "block", "text"). Corresponds to the id from og-widgets/list-widget-types.', 'abilities-catalog' ),
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
	 * Reshapes the adapter's collection envelope to the catalog's `{ items, total }`.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for this collection
	 * route. Each row is projected to the closed widget shape via {@see widgetRow()},
	 * and `total` becomes the count of returned rows (the widgets route returns a
	 * bare array with no `X-WP-Total` header, so the count is the only honest total).
	 * `$input` and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The adapter collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The reshaped collection and count-based total.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$rows  = array();

		foreach ( $items as $widget ) {
			$rows[] = self::widgetRow( is_array( $widget ) ? $widget : array() );
		}

		return array(
			'items' => $rows,
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
