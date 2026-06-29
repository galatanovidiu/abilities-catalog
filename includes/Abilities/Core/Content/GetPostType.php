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
 * Read ability: `og-content/get-post-type`.
 *
 * Wraps `GET /wp/v2/types/<type>` via the Abilities REST Adapter. The input is
 * OVERRIDDEN to the single required `type` slug (the route's `context` query arg
 * is deliberately not exposed, keeping this a public `view` read), and the output
 * is OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}.
 *
 * The wrapped route returns `viewable` and `supports` only in `edit` context, but
 * both are stable, non-sensitive facts about a registered type. Rather than expose
 * an `edit` context (which would tighten the permission to an edit-posts holder),
 * {@see shapeOutput()} derives them from core directly — `is_post_type_viewable()`
 * and `get_all_post_type_supports()` — exactly as the sibling
 * `og-content/list-post-types` derives `supports`.
 *
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set): `GET /wp/v2/types/<type>` registers `__return_true` for a `view`-context
 * read, and the route still self-enforces the object-level checks (404 for an
 * unknown slug, 401/403 for a non-REST type), which surface through `execute()`.
 *
 * @since 0.1.0
 */
final class GetPostType implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-post-type';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/types/(?P<type>[\w-]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Post Type', 'abilities-catalog' ),
				'description'     => __( 'Returns one registered post type by its slug, including its name, description, hierarchical flag, viewable flag, REST base, taxonomies, and supported features. Discover slugs with og-content/list-post-types.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'required'             => array( 'type' ),
					'properties'           => array(
						'type' => array(
							'type'        => 'string',
							'description' => __( 'The post type slug, e.g. "post" or "page". Discover slugs with og-content/list-post-types.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Shapes the REST post-type body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST post-type body (the `view`-context fields). `viewable` and `supports` are
	 * NOT taken from the body — `view` context omits them — but derived from core
	 * directly via `is_post_type_viewable()` and `get_all_post_type_supports()`, so
	 * the read stays public yet still reports them. `$input` and `$response` are part
	 * of the callback signature but unused here — the body plus the resolved slug
	 * carry everything this shape needs.
	 *
	 * @param mixed               $data     The REST post-type body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat post-type fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$slug = (string) ( $data['slug'] ?? '' );
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
