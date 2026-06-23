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
 * Read ability: `content/get-post-type`.
 *
 * Wraps `GET /wp/v2/types/<type>` via `rest_do_request()` and shapes the
 * response into a flat field set. Single-object companion to
 * `content/list-post-types`.
 *
 * The wrapped route returns `viewable` and `supports` only in `edit` context,
 * but both are stable, non-sensitive facts about a registered type. Rather than
 * expose an `edit` context (which would tighten the permission to an
 * edit-posts holder), this ability derives them from core directly —
 * `is_post_type_viewable()` and `get_all_post_type_supports()` — exactly as the
 * sibling `content/list-post-types` derives `supports`. That keeps the read a
 * public `view` read.
 *
 * @since 0.1.0
 */
final class GetPostType implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/get-post-type';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Post Type', 'abilities-catalog' ),
			'description'         => __( 'Returns one registered post type by its slug, including its name, description, hierarchical flag, viewable flag, REST base, taxonomies, and supported features. Discover slugs with content/list-post-types.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'type' ),
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'description' => __( 'The post type slug, e.g. "post" or "page". Discover slugs with content/list-post-types.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'slug', 'name' ),
				'properties'           => array(
					'slug'         => array(
						'type'        => 'string',
						'description' => __( 'The post type slug (its registered name, e.g. "post").', 'abilities-catalog' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'The human-readable post type label.', 'abilities-catalog' ),
					),
					'description'  => array(
						'type'        => 'string',
						'description' => __( 'A human-readable description of the post type, or an empty string if none.', 'abilities-catalog' ),
					),
					'hierarchical' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the type is hierarchical (like pages).', 'abilities-catalog' ),
					),
					'viewable'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the type has a public-facing front-end view (a single template a visitor can open).', 'abilities-catalog' ),
					),
					'rest_base'    => array(
						'type'        => 'string',
						'description' => __( 'The REST base segment for this type\'s collection route; the namespace defaults to wp/v2 but a type may override it.', 'abilities-catalog' ),
					),
					'taxonomies'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'REST-enabled taxonomy slugs associated with the type.', 'abilities-catalog' ),
					),
					'supports'     => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Flat list of supported feature keys (e.g. title, editor, thumbnail).', 'abilities-catalog' ),
					),
					'icon'         => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The Dashicons class or data URI for the admin menu icon, or null if none is set.', 'abilities-catalog' ),
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
	 * `GET /wp/v2/types/<type>` registers `__return_true` as its permission
	 * callback: reading a single registered post type in `view` context is a
	 * public operation in core (post-type registration is not sensitive). This
	 * ability surfaces only `view`-context and independently-derived public
	 * facts, so it mirrors that and returns true. The route still self-enforces
	 * the object-level checks (404 for an unknown slug, 401/403 for a
	 * non-REST type), and returning a coarse capability here would instead mask
	 * an unknown slug as a generic permission denial.
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
	 * Surfaces `view`-context fields from the route, plus `viewable` and
	 * `supports` derived from core directly so the read stays public.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat post-type fields, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = (string) ( $input['type'] ?? '' );

		// Build the route by concatenation so the slug is preserved verbatim.
		$request  = new WP_REST_Request( 'GET', '/wp/v2/types/' . $type );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$slug = (string) ( $data['slug'] ?? $type );
		$icon = $data['icon'] ?? null;

		return array(
			'slug'         => $slug,
			'name'         => (string) ( $data['name'] ?? '' ),
			'description'  => (string) ( $data['description'] ?? '' ),
			'hierarchical' => (bool) ( $data['hierarchical'] ?? false ),
			'viewable'     => is_post_type_viewable( $slug ),
			'rest_base'    => (string) ( $data['rest_base'] ?? '' ),
			'taxonomies'   => isset( $data['taxonomies'] ) && is_array( $data['taxonomies'] ) ? array_values( $data['taxonomies'] ) : array(),
			'supports'     => array_keys( get_all_post_type_supports( $slug ) ),
			'icon'         => null === $icon ? null : (string) $icon,
		);
	}
}
