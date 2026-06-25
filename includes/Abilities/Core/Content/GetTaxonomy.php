<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-content/get-taxonomy`.
 *
 * Wraps `GET /wp/v2/taxonomies/<taxonomy>` via `rest_do_request()` and shapes the
 * response into a flat field set. Single-object companion to the post-type reads.
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
		return array(
			'label'               => __( 'Get Taxonomy', 'abilities-catalog' ),
			'description'         => __( 'Returns one registered taxonomy by its slug, including its name, description, hierarchical flag, the post types it applies to, REST base/namespace, and whether terms are public. Discover slugs with og-content/list-post-types (a type\'s taxonomies) or the terms abilities.', 'abilities-catalog' ),
			'category'            => 'og-core-content',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
	 * Permission check: pure deferral to the wrapped REST route.
	 *
	 * `GET /wp/v2/taxonomies/<taxonomy>` registers `__return_true` as its
	 * permission callback: reading a single registered taxonomy in `view`
	 * context is a public operation in core (taxonomy registration is not
	 * sensitive). This ability surfaces only `view`-context and
	 * independently-derived public facts, so it mirrors that and returns true.
	 * The route still self-enforces the object-level checks (404 for an unknown
	 * slug, 403 for a non-REST taxonomy), and returning a coarse capability here
	 * would instead mask an unknown slug as a generic permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; the wrapped route is the guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * Surfaces `view`-context fields from the route, plus `public` and
	 * `show_cloud` derived from core directly so the read stays public.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat taxonomy fields, or the REST error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = (string) ( $input['taxonomy'] ?? '' );

		// Build the route by concatenation so the slug is preserved verbatim.
		$request  = new WP_REST_Request( 'GET', '/wp/v2/taxonomies/' . $taxonomy );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$slug = (string) ( $data['slug'] ?? $taxonomy );
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
