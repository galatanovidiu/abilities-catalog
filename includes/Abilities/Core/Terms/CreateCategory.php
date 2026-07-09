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
 * T1 safe-write ability: `og-terms/create-category`.
 *
 * Wraps `POST /wp/v2/categories` via the Abilities REST Adapter and returns the
 * new term's id, name, slug, parent, and public archive link. The input schema is
 * OVERRIDDEN to a closed set (`name`, `slug`, `description`, `parent`) so the
 * route's other write fields (`meta`, etc.) stay hidden; the route still
 * sanitizes those it accepts at dispatch. The output is OVERRIDDEN to the
 * catalog's flat five-field set through {@see shapeOutput()}. Permission delegates
 * to the route's own check (no `require_permission` floor is set): the create cap
 * for the hierarchical `category` taxonomy is `manage_categories`, which matches
 * the catalog's previous baseline, so visibility does not widen.
 *
 * @since 0.3.0
 */
final class CreateCategory implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/create-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/categories',
				'method'          => 'POST',
				'label'           => __( 'Create Category', 'abilities-catalog' ),
				'description'     => __( 'Creates a new category term.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The category name (required).', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The category slug. Generated from the name when omitted.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The category description.', 'abilities-catalog' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent category term ID.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name', 'slug' ),
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'The new category term ID.', 'abilities-catalog' ),
						),
						'name'   => array(
							'type'        => 'string',
							'description' => __( 'The category name.', 'abilities-catalog' ),
						),
						'slug'   => array(
							'type'        => 'string',
							'description' => __( 'The category slug.', 'abilities-catalog' ),
						),
						'parent' => array(
							'type'        => 'integer',
							'description' => __( 'The parent category term ID (0 when top-level).', 'abilities-catalog' ),
						),
						'link'   => array(
							'type'        => 'string',
							'description' => __( 'The public category archive URL.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'edit-tags.php?taxonomy=category',
				),
			)
		);
	}

	/**
	 * Flattens the REST term body to the catalog's five-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST term body. Each field copies across with a type cast and a safe default.
	 * `$input` and `$response` are part of the callback signature but unused here —
	 * the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST term body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat term fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'     => (int) ( $data['id'] ?? 0 ),
			'name'   => (string) ( $data['name'] ?? '' ),
			'slug'   => (string) ( $data['slug'] ?? '' ),
			'parent' => (int) ( $data['parent'] ?? 0 ),
			'link'   => (string) ( $data['link'] ?? '' ),
		);
	}
}
