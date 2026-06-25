<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-terms/list-taxonomies`.
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
		return 'og-terms/list-taxonomies';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Taxonomies', 'abilities-catalog' ),
			'description'         => __( 'Returns the registered taxonomies that are exposed in the REST API. Use a returned "slug" as the "taxonomy" input to og-terms/list-terms. The default "view" context returns public taxonomies.', 'abilities-catalog' ),
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
							'required'             => array( 'name', 'slug', 'types', 'hierarchical', 'rest_base' ),
							'properties'           => array(
								'name'         => array(
									'type'        => 'string',
									'description' => __( 'Human-readable taxonomy label.', 'abilities-catalog' ),
								),
								'slug'         => array(
									'type'        => 'string',
									'description' => __( 'Taxonomy slug; pass as the "taxonomy" input to og-terms/list-terms.', 'abilities-catalog' ),
								),
								'types'        => array(
									'type'        => 'array',
									'description' => __( 'Object types (post types) this taxonomy is registered for.', 'abilities-catalog' ),
									'items'       => array(
										'type' => 'string',
									),
								),
								'hierarchical' => array(
									'type'        => 'boolean',
									'description' => __( 'Whether the taxonomy is hierarchical (like categories) or flat (like tags).', 'abilities-catalog' ),
								),
								'rest_base'    => array(
									'type'        => 'string',
									'description' => __( 'REST API base for the taxonomy.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
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
			return RestError::from( $response );
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
