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
 * T1 safe-write ability: `og-terms/create-tag`.
 *
 * Wraps `POST /wp/v2/tags` via the Abilities REST Adapter. The input schema is
 * OVERRIDDEN to the catalog's closed `name`/`slug`/`description` set (so raw REST
 * term fields stay hidden); the route validates and sanitizes those fields itself.
 * The output is OVERRIDDEN to the catalog's flat `id`/`name`/`slug`/`link` set
 * through {@see shapeOutput()}. Permission delegates to the route's own check (no
 * `require_permission` floor is set) — for `POST /wp/v2/tags` that is the
 * `post_tag` taxonomy's `assign_terms` capability, the same cap the catalog
 * enforced by hand before conversion.
 *
 * @since 0.3.0
 */
final class CreateTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/create-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/tags',
				'method'          => 'POST',
				'label'           => __( 'Create Tag', 'abilities-catalog' ),
				'description'     => __( 'Creates a new tag term.', 'abilities-catalog' ),
				'category'        => 'og-core-terms',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The tag name (required).', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The tag slug. Generated from the name when omitted.', 'abilities-catalog' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'The tag description.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'name', 'slug' ),
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => __( 'The new tag term ID.', 'abilities-catalog' ),
						),
						'name' => array(
							'type'        => 'string',
							'description' => __( 'The tag name.', 'abilities-catalog' ),
						),
						'slug' => array(
							'type'        => 'string',
							'description' => __( 'The tag slug.', 'abilities-catalog' ),
						),
						'link' => array(
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
					'screen'       => 'edit-tags.php?taxonomy=post_tag',
				),
			)
		);
	}

	/**
	 * Flattens the REST term body to the catalog's id/name/slug/link set.
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
			'id'   => (int) ( $data['id'] ?? 0 ),
			'name' => (string) ( $data['name'] ?? '' ),
			'slug' => (string) ( $data['slug'] ?? '' ),
			'link' => (string) ( $data['link'] ?? '' ),
		);
	}
}
