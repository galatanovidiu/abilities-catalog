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
 * Read ability: `og-widgets/list-widget-types`.
 *
 * Wraps `GET /wp/v2/widget-types` via `rest_do_request()` and returns the
 * available widget types — the valid `id_base` values for
 * `og-widgets/create-widget`. The collection route returns a bare JSON array (no
 * `X-WP-Total` header), so `total` is derived with `count()` over the shaped
 * rows. Each raw widget type carries `id`, `name`, `description`, `is_multi`,
 * and `classname`; this projects down to the four agent-useful fields and drops
 * the internal `classname`/`_links`.
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
		return array(
			'label'               => __( 'List Widget Types', 'abilities-catalog' ),
			'description'         => __( 'Lists the available widget types (the valid id_base values for og-widgets/create-widget), each with its slug, display name, description, and whether it supports multiple instances. Call this before og-widgets/create-widget to discover what kinds of widgets can be added.', 'abilities-catalog' ),
			'category'            => 'widgets',
			'input_schema'        => array(),
			'output_schema'       => array(
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
	 * Permission check: baseline `edit_theme_options` to read widget types.
	 *
	 * Mirrors the wrapped route's own guard: `WP_REST_Widget_Types_Controller::
	 * get_items_permissions_check()` calls `check_read_permission()`, which
	 * requires `edit_theme_options`. This coarse, object-independent gate is the
	 * catalog baseline for the widgets cluster and is not weaker than core.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read widget types.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The widget types and total, or the REST error.
	 */
	public function execute( $input = null ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/widget-types' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		if ( is_array( $data ) ) {
			foreach ( $data as $type ) {
				$items[] = array(
					'id'          => (string) ( $type['id'] ?? '' ),
					'name'        => (string) ( $type['name'] ?? '' ),
					'description' => (string) ( $type['description'] ?? '' ),
					'is_multi'    => (bool) ( $type['is_multi'] ?? false ),
				);
			}
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}
}
