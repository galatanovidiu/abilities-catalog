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
 * Read ability: `og-terms/get-category`.
 *
 * Wraps `GET /wp/v2/categories/<id>` via the Abilities REST Adapter and shapes
 * the response into a flat field set through {@see shapeOutput()}. Permission
 * delegates to the route's own check (no `require_permission` floor): category
 * reads are public in `view`, and the route requires `manage_categories` for `edit`.
 *
 * @since 0.1.0
 */
final class GetCategory implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/get-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/categories/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Category', 'abilities-catalog' ),
				'description'     => __( 'Returns a single category term by ID.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The category term ID. Discover IDs with og-terms/list-categories.', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name', 'slug' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The term ID.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The term name.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The term slug.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The term description.', 'abilities-catalog' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent term ID.', 'abilities-catalog' ),
						),
						'count'       => array(
							'type'        => 'integer',
							'description' => __( 'Number of objects assigned to the term.', 'abilities-catalog' ),
						),
						'taxonomy'    => array(
							'type'        => 'string',
							'description' => __( 'The taxonomy the term belongs to.', 'abilities-catalog' ),
						),
						'link'        => array(
							'type'        => 'string',
							'description' => __( 'The public term archive URL.', 'abilities-catalog' ),
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
	 * Maps the REST term body to the catalog's flat output shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. `$input['id']`
	 * is the fallback ID when the body omits its own. `$response` is part of the
	 * callback signature but unused here.
	 *
	 * @param mixed               $data     The REST term body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat term fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = absint( $input['id'] ?? 0 );

		return array(
			'id'          => (int) ( $data['id'] ?? $id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => (int) ( $data['parent'] ?? 0 ),
			'count'       => (int) ( $data['count'] ?? 0 ),
			'taxonomy'    => (string) ( $data['taxonomy'] ?? 'category' ),
			'link'        => (string) ( $data['link'] ?? '' ),
		);
	}
}
