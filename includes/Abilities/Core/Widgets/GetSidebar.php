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
 * Read ability: `og-widgets/get-sidebar`.
 *
 * Wraps `GET /wp/v2/sidebars/<id>` via the Abilities REST Adapter. The input
 * schema is DERIVED from the route — the path capture `id` (a sidebar slug like
 * "sidebar-1" or "wp_inactive_widgets") becomes the required input. The output
 * is OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}.
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set), so visibility follows REST (`edit_theme_options`), not the catalog's old
 * baseline. An unknown id surfaces the route's `rest_sidebar_not_found` 404, not
 * a permission collapse — object existence is deferred to the wrapped route.
 *
 * @since 0.1.0
 */
final class GetSidebar implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/get-sidebar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/sidebars/(?P<id>[\w-]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Sidebar', 'abilities-catalog' ),
				'description'     => __( 'Returns a single sidebar (widget area) by id, including its name, status (active or inactive), and the ordered list of widget instance ids it contains. Single-object companion to og-widgets/list-sidebars; discover sidebar ids there.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name', 'status', 'widgets' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'string',
							'description' => __( 'The sidebar slug.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The registered display name of the sidebar.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The sidebar description, or an empty string when none is set.', 'abilities-catalog' ),
						),
						'status'      => array(
							'type'        => 'string',
							'description' => __( 'Whether the sidebar is "active" (registered by the active theme) or "inactive" (e.g. wp_inactive_widgets, or any sidebar under a block theme).', 'abilities-catalog' ),
						),
						'widgets'     => array(
							'type'        => 'array',
							'description' => __( 'The ordered widget instance ids in this sidebar (e.g. "block-3", "text-2"). Read a single widget with og-widgets/get-widget.', 'abilities-catalog' ),
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
	 * Flattens the REST sidebar body to the catalog's five-field projection.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST sidebar body. The ordered `widgets` list is re-indexed with `array_values`
	 * so it serializes as a JSON array; the other fields copy across with a type cast
	 * and a safe default, so a value REST omits comes back as `''` rather than a
	 * missing key. `$input` and `$response` are part of the callback signature but
	 * unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST sidebar body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat sidebar projection.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$widgets = isset( $data['widgets'] ) && is_array( $data['widgets'] ) ? array_values( $data['widgets'] ) : array();

		return array(
			'id'          => (string) ( $data['id'] ?? '' ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'status'      => (string) ( $data['status'] ?? '' ),
			'widgets'     => $widgets,
		);
	}
}
