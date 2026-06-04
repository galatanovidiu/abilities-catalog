<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `plugins/list-plugins`.
 *
 * Wraps `GET /wp/v2/plugins` via `rest_do_request()` and returns the list of
 * installed plugins. The plugins route returns a flat list with no pagination
 * headers (`X-WP-Total`), so no total is exposed. Each item is passed through
 * with its native shape; the list schema does not constrain item fields.
 *
 * @since 0.1.0
 */
final class ListPlugins implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'plugins/list-plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Plugins', 'abilities-catalog' ),
			'description'         => __( 'Returns the list of installed plugins, optionally filtered by search term or activation status.', 'abilities-catalog' ),
			'category'            => 'plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'  => array(
						'type'        => 'string',
						'description' => __( 'Limit results to plugins matching a search term.', 'abilities-catalog' ),
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'active', 'inactive' ),
						'description' => __( 'Limit results to plugins with the given activation status.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The installed plugins.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
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
	 * Permission check: the current user may manage plugin activation.
	 *
	 * Encodes the catalog capability for `plugins/list-plugins` (`activate_plugins`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read installed plugins.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The list of plugins, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/plugins' );
		$request->set_param( 'context', (string) ( $input['context'] ?? 'view' ) );
		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		if ( ! empty( $input['status'] ) ) {
			$request->set_param( 'status', (string) $input['status'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$items = is_array( $data ) ? array_values( $data ) : array();

		return array(
			'items' => $items,
		);
	}
}
