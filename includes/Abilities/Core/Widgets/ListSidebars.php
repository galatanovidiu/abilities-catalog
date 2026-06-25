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
 * Read ability: `og-widgets/list-sidebars`.
 *
 * Wraps `GET /wp/v2/sidebars` via `rest_do_request()` and returns each registered
 * sidebar (widget area) with its ordered widget instance ids, including the special
 * `wp_inactive_widgets` holding area. Read-only.
 *
 * Requests `context=view`; the sidebars controller serves `id`, `name`,
 * `description`, `status`, and `widgets` in view context (see
 * `WP_REST_Sidebars_Controller::get_item_schema()`), so the flat projection has every
 * field it needs. The collection route returns a bare JSON array (not a paginated
 * envelope) and emits no `X-WP-Total` header, so `total` is `count($items)`.
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
		return array(
			'label'               => __( 'List Sidebars', 'abilities-catalog' ),
			'description'         => __( 'Lists the site\'s sidebars (widget areas), each with its id, name, description, status (active or inactive), and the ordered ids of the widgets it holds. Includes the special "wp_inactive_widgets" holding area. Use a returned sidebar id as the target for og-widgets/create-widget, and og-widgets/get-sidebar for one sidebar.', 'abilities-catalog' ),
			'category'            => 'og-core-widgets',
			'input_schema'        => array(),
			'output_schema'       => array(
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
	 * Permission check: baseline `edit_theme_options` to read sidebars.
	 *
	 * `edit_theme_options` is the capability the sidebars REST route requires
	 * (`WP_REST_Sidebars_Controller::do_permissions_check()`) and the catalog
	 * baseline for the widgets cluster; it is not weaker than core. The check is
	 * coarse and object-independent — this list has no per-object target.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list sidebars.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The sidebar collection and total, or the REST error.
	 */
	public function execute( $input = null ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/sidebars' );
		$request->set_param( 'context', 'view' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$rows = is_array( $data ) ? array_map( array( $this, 'sidebarSummary' ), $data ) : array();

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
