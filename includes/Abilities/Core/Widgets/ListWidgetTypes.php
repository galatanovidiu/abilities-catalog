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
 * Read ability: `og-widgets/list-widget-types`.
 *
 * Wraps `GET /wp/v2/widget-types` via the Abilities REST Adapter and returns the
 * available widget types — the valid `id_base` values for
 * `og-widgets/create-widget`. The route is a collection (`get_items`), so the
 * adapter wraps the body into a `{ items, total, total_pages }` envelope; the
 * output is OVERRIDDEN through {@see shapeOutput()} to the catalog's flat
 * `{ items, total }` shape, projecting each widget type down to the four
 * agent-useful fields and dropping the internal `classname`/`_links`. The input
 * stays a zero-arg call via the bare `input_schema`. Permission delegates to the
 * route's own check (`check_read_permission()`, `edit_theme_options`), so no
 * `require_permission` floor is set — the route is the authority.
 *
 * @since 0.1.0
 */
final class ListWidgetTypes implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/list-widget-types';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/widget-types',
				'method'          => 'GET',
				'label'           => __( 'List Widget Types', 'abilities-catalog' ),
				'description'     => __( 'Lists the available widget types (the valid id_base values for og-widgets/create-widget), each with its slug, display name, description, and whether it supports multiple instances. Call this before og-widgets/create-widget to discover what kinds of widgets can be added.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'input_schema'    => array(),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'total' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'id', 'name' ),
								'properties'           => array(
									'id'          => array(
										'type'        => 'string',
										'description' => __( 'The widget type slug — pass it as id_base to og-widgets/create-widget (e.g. "block", "text").', 'abilities-catalog' ),
									),
									'name'        => array(
										'type'        => 'string',
										'description' => __( 'The human-readable widget type name.', 'abilities-catalog' ),
									),
									'description' => array(
										'type'        => 'string',
										'description' => __( 'A short description of what the widget type does.', 'abilities-catalog' ),
									),
									'is_multi'    => array(
										'type'        => 'boolean',
										'description' => __( 'Whether the widget type supports multiple instances on the site.', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The available widget types as flat rows. Use a row\'s id as the id_base for og-widgets/create-widget.', 'abilities-catalog' ),
						),
						'total' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of widget types returned.', 'abilities-catalog' ),
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
	 * Projects the collection envelope to the catalog's flat `{ items, total }` shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for a collection
	 * route. Each raw widget type is reshaped to the four agent-useful fields with a
	 * type cast and a safe default, dropping the internal `classname`/`_links`.
	 * `total` is derived with `count()` over the shaped rows, mirroring the original
	 * behavior for this header-less collection. `$input` and `$response` are part of
	 * the callback signature but unused here — the envelope carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped widget type rows and total.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$types = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items = array();

		foreach ( $types as $type ) {
			$type    = is_array( $type ) ? $type : array();
			$items[] = array(
				'id'          => (string) ( $type['id'] ?? '' ),
				'name'        => (string) ( $type['name'] ?? '' ),
				'description' => (string) ( $type['description'] ?? '' ),
				'is_multi'    => (bool) ( $type['is_multi'] ?? false ),
			);
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}
}
