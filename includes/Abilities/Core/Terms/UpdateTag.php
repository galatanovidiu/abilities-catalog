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
 * T1 safe-write ability: `og-terms/update-tag`.
 *
 * Wraps `POST /wp/v2/tags/<id>` via the Abilities REST Adapter. The input schema
 * is OVERRIDDEN to the catalog's closed set (`id` required, plus optional `name`,
 * `slug`, `description`); the route re-sanitizes and re-validates these fields
 * underneath at dispatch. The output is OVERRIDDEN to the catalog's flat field set
 * through {@see shapeOutput()}. Permission delegates to the route's own check (no
 * `require_permission` floor is set): for `post_tag`, `edit_term` maps to
 * `edit_post_tags` with no owner split, so the route's check is exactly the coarse
 * cap the catalog used — never stricter, never weaker. A non-existent id surfaces
 * the route's specific `rest_term_invalid` 404 through `execute()` rather than the
 * generic denial.
 *
 * @since 0.3.0
 */
final class UpdateTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/update-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/tags/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Tag', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing tag term by ID.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The tag term ID (required).', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The tag name.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The tag slug.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The tag description.', 'abilities-catalog' ),
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
							'description' => __( 'The tag term ID.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The tag name.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The tag slug.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The tag description.', 'abilities-catalog' ),
						),
						'link'        => array(
							'type'        => 'string',
							'description' => __( 'The public tag archive URL.', 'abilities-catalog' ),
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
					'screen'       => 'term.php?taxonomy=post_tag&tag_ID={id}',
				),
			)
		);
	}

	/**
	 * Flattens the REST term body to the catalog's 5-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST term body. The fields copy across with a type cast and a safe default, so
	 * a value REST omits comes back as `''`/`0` rather than a missing key. `$input`
	 * and `$response` are part of the callback signature but unused here — the body
	 * carries everything this shape needs.
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
			'link'        => (string) ( $data['link'] ?? '' ),
		);
	}
}
