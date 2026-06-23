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
 * Read ability: `widgets/get-sidebar`.
 *
 * Wraps `GET /wp/v2/sidebars/<id>` via `rest_do_request()` and shapes the
 * response into a flat sidebar projection (id, name, description, status, and
 * the ordered widget instance ids in the sidebar). Single-object companion to
 * `widgets/list-sidebars`.
 *
 * The id is a sidebar slug (e.g. "sidebar-1", "wp_inactive_widgets") and is
 * concatenated into the route path. An unknown id surfaces the route's
 * `rest_sidebar_not_found` 404 via `RestError::from()`, not a permission
 * collapse — object existence is deferred to the wrapped route.
 *
 * @since 0.1.0
 */
final class GetSidebar implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'widgets/get-sidebar';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Sidebar', 'abilities-catalog' ),
			'description'         => __( 'Returns a single sidebar (widget area) by id, including its name, status (active or inactive), and the ordered list of widget instance ids it contains. Single-object companion to widgets/list-sidebars; discover sidebar ids there.', 'abilities-catalog' ),
			'category'            => 'widgets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'description' => __( 'The sidebar slug, e.g. "sidebar-1" or "wp_inactive_widgets". Discover sidebar ids with widgets/list-sidebars.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
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
						'description' => __( 'The ordered widget instance ids in this sidebar (e.g. "block-3", "text-2"). Read a single widget with widgets/get-widget.', 'abilities-catalog' ),
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
	 * Permission check: baseline `edit_theme_options` to read a sidebar.
	 *
	 * Coarse, object-independent gate encoding the catalog baseline for the
	 * widgets cluster. The wrapped sidebars route checks `edit_theme_options`
	 * itself (`WP_REST_Sidebars_Controller::do_permissions_check()`), so this
	 * is not weaker than core. Object existence is deferred to the route so an
	 * unknown id surfaces as `rest_sidebar_not_found` 404, not a generic
	 * permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read sidebars.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat sidebar projection, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// Concatenate the id into the route so a slug like "wp_inactive_widgets" is passed verbatim.
		$request = new WP_REST_Request( 'GET', '/wp/v2/sidebars/' . $id );
		$request->set_param( 'context', 'view' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$widgets = isset( $data['widgets'] ) && is_array( $data['widgets'] ) ? array_values( $data['widgets'] ) : array();

		return array(
			'id'          => (string) ( $data['id'] ?? $id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'status'      => (string) ( $data['status'] ?? '' ),
			'widgets'     => $widgets,
		);
	}
}
