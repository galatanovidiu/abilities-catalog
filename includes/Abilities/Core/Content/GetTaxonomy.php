<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-content/get-taxonomy`.
 *
 * Wraps `GET /wp/v2/taxonomies/<taxonomy>` via the Abilities REST Adapter and
 * shapes the response into a flat field set. Single-object companion to the
 * post-type reads. Permission delegates to the route's own check (no
 * `require_permission` floor), and the wrapped route allows a public `view`
 * read of a registered taxonomy.
 *
 * The wrapped route returns `show_cloud` and the `visibility.public` flag only in
 * `edit` context, but both are stable, non-sensitive facts about a registered
 * taxonomy. Rather than expose an `edit` context (which would tighten the
 * permission to an edit-posts holder), this ability derives them from core
 * directly — `get_taxonomy()->show_tagcloud` and `get_taxonomy()->public` —
 * exactly as the sibling `og-content/get-post-type` derives `viewable` and
 * `supports`. That keeps the read a public `view` read.
 *
 * @since 0.1.0
 */
final class GetTaxonomy implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-taxonomy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/taxonomies/(?P<taxonomy>[\w-]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Taxonomy', 'abilities-catalog' ),
				'description'     => __( 'Returns one registered taxonomy by its slug, including its name, description, hierarchical flag, the post types it applies to, REST base/namespace, and whether terms are public. Discover slugs with og-content/list-post-types (a type\'s taxonomies) or the terms abilities.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'required'             => array( 'taxonomy' ),
					'properties'           => array(
						'taxonomy' => array(
							'type'        => 'string',
							'description' => __( 'The taxonomy slug, e.g. "category" or "post_tag". Discover slugs with og-content/list-post-types (a type\'s taxonomies) or the terms abilities.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'slug', 'name' ),
					'properties'           => array(
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'The taxonomy slug (its registered name, e.g. "category").', 'abilities-catalog' ),
						),
						'name'           => array(
							'type'        => 'string',
							'description' => __( 'The human-readable taxonomy label.', 'abilities-catalog' ),
						),
						'description'    => array(
							'type'        => 'string',
							'description' => __( 'A human-readable description of the taxonomy, or an empty string if none.', 'abilities-catalog' ),
						),
						'hierarchical'   => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the taxonomy is hierarchical (like categories) rather than flat (like tags).', 'abilities-catalog' ),
						),
						'types'          => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'The post type slugs this taxonomy applies to.', 'abilities-catalog' ),
						),
						'rest_base'      => array(
							'type'        => 'string',
							'description' => __( 'The REST base segment for this taxonomy\'s term collection route (e.g. "categories").', 'abilities-catalog' ),
						),
						'rest_namespace' => array(
							'type'        => 'string',
							'description' => __( 'The REST namespace for this taxonomy\'s route; defaults to wp/v2 but a taxonomy may override it.', 'abilities-catalog' ),
						),
						'public'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the taxonomy is intended for public use (front-end term archives and queries).', 'abilities-catalog' ),
						),
						'show_cloud'     => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the term cloud widget is offered for this taxonomy.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Shapes the REST taxonomy body into the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST taxonomy body. The `view`-context fields copy across with a type cast and
	 * a safe default. `public` and `show_cloud` are NOT in the `view` body, so they
	 * are derived from core directly — `get_taxonomy()->public` and
	 * `->show_tagcloud` — keeping the read a public `view` read rather than forcing an
	 * `edit` context (which would tighten permission to an edit-posts holder). The
	 * slug falls back to the original input taxonomy when the body omits it. The
	 * `$response` argument is part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST taxonomy body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat taxonomy fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$slug = (string) ( $data['slug'] ?? ( $input['taxonomy'] ?? '' ) );
		$tax  = get_taxonomy( $slug );

		return array(
			'slug'           => $slug,
			'name'           => (string) ( $data['name'] ?? '' ),
			'description'    => (string) ( $data['description'] ?? '' ),
			'hierarchical'   => (bool) ( $data['hierarchical'] ?? false ),
			'types'          => isset( $data['types'] ) && is_array( $data['types'] ) ? array_values( $data['types'] ) : array(),
			'rest_base'      => (string) ( $data['rest_base'] ?? '' ),
			'rest_namespace' => (string) ( $data['rest_namespace'] ?? '' ),
			'public'         => $tax ? (bool) $tax->public : false,
			'show_cloud'     => $tax ? (bool) $tax->show_tagcloud : false,
		);
	}
}
