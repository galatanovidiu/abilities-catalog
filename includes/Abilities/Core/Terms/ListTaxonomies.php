<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-terms/list-taxonomies`.
 *
 * Wraps `GET /wp/v2/taxonomies` via the Abilities REST Adapter. The route returns
 * an object keyed by taxonomy slug (not a list), so the adapter passes the body
 * through unchanged and {@see shapeOutput()} converts it into a flat list of
 * objects so the output matches the list shape used elsewhere. Permission delegates
 * to the route's own check (no `require_permission` floor): the route is public in
 * `view` and requires `assign_terms` on a REST-exposed taxonomy in `edit` context.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/taxonomies',
				'method'          => 'GET',
				'label'           => __( 'List Taxonomies', 'abilities-catalog' ),
				'description'     => __( 'Returns the registered taxonomies that are exposed in the REST API. Use a returned "slug" as the "taxonomy" input to og-terms/list-terms. The default "view" context returns public taxonomies.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Normalises the slug-keyed `/wp/v2/taxonomies` body into a flat list of objects.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. The route's
	 * body is an object keyed by slug, so it is iterated (not the collection
	 * envelope) and each taxonomy flattened. `$input` and `$response` are part of
	 * the callback signature but unused here.
	 *
	 * @param mixed               $data     The slug-keyed taxonomies object.
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The normalised list under `items`.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
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
