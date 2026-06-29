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
 * T1 safe-write ability: `og-terms/update-category`.
 *
 * Wraps `POST /wp/v2/categories/<id>` via the Abilities REST Adapter. The input
 * schema is OVERRIDDEN to a closed `{ id, name, slug, description, parent }` set —
 * `id` is required (the route's path capture, consumed from the input at dispatch),
 * the rest optional. The output is OVERRIDDEN to the catalog's flat field set
 * through {@see shapeOutput()}. Permission delegates to the route's own check (no
 * `require_permission` floor is set), so a missing-id 404 and the route's own
 * `rest_term_invalid` reach the caller as the real REST error.
 *
 * The current update-semantics nuance — an explicit empty `name`/`slug` is the
 * caller's "blank this field" intent and must reach core (the validator) — is
 * preserved by the adapter at dispatch: it forwards exactly the supplied keys with
 * no schema defaults injected, so an explicit `''` stays `''` and surfaces core's
 * `empty_term_name` (name) or slug regeneration (slug). No `input_callback` needed.
 *
 * @since 0.3.0
 */
final class UpdateCategory implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/update-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/categories/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Category', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing category term by ID.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The category term ID (required). Discover IDs via og-terms/list-categories.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The category name.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The category slug.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The category description.', 'abilities-catalog' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent category term ID. Pass 0 to clear the parent and make the category top-level.', 'abilities-catalog' ),
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
							'description' => __( 'The category term ID.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The category name.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The category slug.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The category description.', 'abilities-catalog' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent category term ID (0 when top-level).', 'abilities-catalog' ),
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
					'screen'       => 'term.php?taxonomy=category&tag_ID={id}',
				),
			)
		);
	}

	/**
	 * Flattens the REST term body to the catalog's 5-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST term body. Each field copies across with a type cast and a safe default,
	 * so a value REST omits comes back as a zero/empty rather than a missing key.
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
			'id'          => (int) ( $data['id'] ?? 0 ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => (int) ( $data['parent'] ?? 0 ),
		);
	}
}
