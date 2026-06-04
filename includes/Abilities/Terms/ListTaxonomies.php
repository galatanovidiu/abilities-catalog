<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `terms/list-taxonomies`.
 *
 * Wraps `GET /wp/v2/taxonomies` via `rest_do_request()`. The REST endpoint
 * returns an object keyed by taxonomy slug; this ability converts it into a
 * flat list of objects so the output matches the list shape used elsewhere.
 *
 * @since 0.1.0
 */
final class ListTaxonomies implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/list-taxonomies';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Taxonomies', 'abilities-catalog' ),
			'description'         => __( 'Returns the registered taxonomies that are exposed in the REST API.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
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
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'description' => __( 'The registered, REST-exposed taxonomies.', 'abilities-catalog' ),
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
	 * Permission check: discovery requires an authenticated user.
	 *
	 * Edit-context additionally requires `edit_posts`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list taxonomies.
	 */
	public function hasPermission( $input ): bool {
		$input   = is_array( $input ) ? $input : array();
		$context = $input['context'] ?? 'view';

		if ( 'edit' === $context ) {
			return current_user_can( 'edit_posts' );
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error List of taxonomy objects, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/taxonomies' );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$items = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $taxonomy ) {
				if ( ! is_array( $taxonomy ) ) {
					continue;
				}
				$items[] = array(
					'name'         => (string) ( $taxonomy['name'] ?? '' ),
					'slug'         => (string) ( $taxonomy['slug'] ?? '' ),
					'types'        => array_values( (array) ( $taxonomy['types'] ?? array() ) ),
					'hierarchical' => (bool) ( $taxonomy['hierarchical'] ?? false ),
					'rest_base'    => (string) ( $taxonomy['rest_base'] ?? '' ),
				);
			}
		}

		return array(
			'items' => $items,
		);
	}
}
