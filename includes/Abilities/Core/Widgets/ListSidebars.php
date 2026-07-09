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
 * Read ability: `og-widgets/list-sidebars`.
 *
 * Wraps `GET /wp/v2/sidebars` via the Abilities REST Adapter and returns each
 * registered sidebar (widget area) with its ordered widget instance ids, including
 * the special `wp_inactive_widgets` holding area. Read-only.
 *
 * The input is OVERRIDDEN to the canonical no-arg schema (empty), keeping the
 * existing zero-argument contract and hiding the route's optional query args. The
 * sidebars controller's `get_items` callback makes the adapter treat the route as a
 * collection and wrap the bare REST array into `{ items, total, total_pages }`; the
 * output is then OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}, which projects each row and returns `{ items, total }`.
 * Permission delegates to the route's own check (no `require_permission` floor),
 * which requires `edit_theme_options`.
 *
 * @since 0.1.0
 */
final class ListSidebars implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/list-sidebars';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/sidebars',
				'method'          => 'GET',
				'label'           => __( 'List Sidebars', 'abilities-catalog' ),
				'description'     => __( 'Lists the site\'s sidebars (widget areas), each with its id, name, description, status (active or inactive), and the ordered ids of the widgets it holds. Includes the special "wp_inactive_widgets" holding area. Use a returned sidebar id as the target for og-widgets/create-widget, and og-widgets/get-sidebar for one sidebar.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'input_schema'    => array(),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'total' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'items'       => self::sidebarItemSchema(),
							'description' => __( 'The list of sidebars as flat rows. Use og-widgets/get-sidebar for a single sidebar.', 'abilities-catalog' ),
						),
						'total' => array(
							'type'        => 'integer',
							'description' => __( 'The number of sidebars returned (equal to the length of items).', 'abilities-catalog' ),
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
	 * Projects the REST sidebars collection to the catalog's `{ items, total }` shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. The route's
	 * `get_items` callback makes the adapter wrap the bare REST array into
	 * `{ items, total, total_pages }` before this runs, so the rows arrive under
	 * `items`; the bare-array fallback covers a non-collection arrival. Each row is
	 * flattened through {@see sidebarSummary()}, and `total` is the count of returned
	 * rows. `$input` and `$response` are part of the callback signature but unused.
	 *
	 * @param mixed               $data     The collection envelope or bare sidebars array.
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat sidebar rows and their total.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		if ( is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$sidebars = $data['items'];
		} else {
			$sidebars = is_array( $data ) ? $data : array();
		}

		$rows = array_map( array( $this, 'sidebarSummary' ), $sidebars );

		return array(
			'items' => array_values( $rows ),
			'total' => count( $rows ),
		);
	}

	/**
	 * Projects one REST sidebar object to the flat closed row.
	 *
	 * @param mixed $sidebar One sidebar object from the REST response.
	 * @return array<string,mixed> The flat sidebar row.
	 */
	private function sidebarSummary( $sidebar ): array {
		$sidebar = is_array( $sidebar ) ? $sidebar : array();
		$widgets = isset( $sidebar['widgets'] ) && is_array( $sidebar['widgets'] ) ? array_values( $sidebar['widgets'] ) : array();

		return array(
			'id'          => (string) ( $sidebar['id'] ?? '' ),
			'name'        => (string) ( $sidebar['name'] ?? '' ),
			'description' => (string) ( $sidebar['description'] ?? '' ),
			'status'      => (string) ( $sidebar['status'] ?? '' ),
			'widgets'     => $widgets,
		);
	}

	/**
	 * The closed item schema for one sidebar row.
	 *
	 * @return array<string,mixed> The JSON schema for a sidebar row.
	 */
	private static function sidebarItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'name', 'status', 'widgets' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'string',
					'description' => __( 'The sidebar id (slug), e.g. "sidebar-1" or "wp_inactive_widgets".', 'abilities-catalog' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The registered display name of the sidebar.', 'abilities-catalog' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The sidebar description, or an empty string when none is registered.', 'abilities-catalog' ),
				),
				'status'      => array(
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive' ),
					'description' => __( 'Whether the sidebar is registered by the active theme ("active") or only a holding area such as wp_inactive_widgets ("inactive"). Block themes report every sidebar as "inactive".', 'abilities-catalog' ),
				),
				'widgets'     => array(
					'type'        => 'array',
					'description' => __( 'The ordered widget instance ids currently in this sidebar (e.g. "block-3"). Empty when the sidebar holds no widgets.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
