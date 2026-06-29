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
 * T1 write ability: `og-content/update-post`.
 *
 * Wraps `POST /wp/v2/posts/<id>` via the Abilities REST Adapter. The input
 * schema is OVERRIDDEN to the catalog's curated field set (`id` required, plus
 * the optional title/content/excerpt/status/author/slug/date/categories/tags/
 * featured_media), so the raw REST fields the catalog does not expose stay
 * hidden. The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}, which also derives the wp-admin `edit_link`. Permission
 * delegates to the route's own check (no `require_permission` floor): the route's
 * object-level `edit_post` check is the authority and is not looser than the
 * catalog's old coarse `edit_posts` baseline, so a route denial (e.g.
 * `rest_cannot_edit`) surfaces through `execute()` as the real REST error.
 *
 * The adapter forwards exactly the supplied input keys — no schema defaults are
 * injected — so a present key with an empty value (e.g. `title => ''`,
 * `featured_media => 0`, `categories => []`) passes through to core, preserving
 * the catalog's clear-field semantics.
 *
 * @since 0.2.0
 */
final class UpdatePost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/update-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Post', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing post by ID. Only the provided fields change. Set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'             => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The post ID to update.', 'abilities-catalog' ),
						),
						'title'          => array(
							'type'        => 'string',
							'description' => __( 'The post title.', 'abilities-catalog' ),
						),
						'content'        => array(
							'type'        => 'string',
							'description' => __( 'The post content as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->. Bare HTML is accepted but stored as a single classic block. Use og-templates/list-block-types to discover available blocks.', 'abilities-catalog' ),
						),
						'excerpt'        => array(
							'type'        => 'string',
							'description' => __( 'The post excerpt.', 'abilities-catalog' ),
						),
						'status'         => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
							'description' => __( 'The post status.', 'abilities-catalog' ),
						),
						'author'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The author user ID. Setting another user requires the edit_others_posts capability.', 'abilities-catalog' ),
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'The post slug.', 'abilities-catalog' ),
						),
						'date'           => array(
							'type'        => 'string',
							'format'      => 'date-time',
							'description' => __( 'The publish date in site time (ISO 8601).', 'abilities-catalog' ),
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'description' => __( 'Category term IDs to assign.', 'abilities-catalog' ),
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'description' => __( 'Tag term IDs to assign.', 'abilities-catalog' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Attachment ID for the featured image, or 0 to detach the current one.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status', 'link', 'edit_link' ),
					'properties'           => array(
						'id'             => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'abilities-catalog' ),
						),
						'title'          => array(
							'type'        => 'string',
							'description' => __( 'The rendered post title.', 'abilities-catalog' ),
						),
						'link'           => array(
							'type'        => 'string',
							'description' => __( 'The post permalink.', 'abilities-catalog' ),
						),
						'status'         => array(
							'type'        => 'string',
							'description' => __( 'The resulting post status.', 'abilities-catalog' ),
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'The resulting post slug, after core sanitization and uniquification.', 'abilities-catalog' ),
						),
						'modified'       => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => __( 'The resulting featured image attachment ID, or 0 if none is set.', 'abilities-catalog' ),
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'The resulting assigned category term IDs.', 'abilities-catalog' ),
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'The resulting assigned tag term IDs.', 'abilities-catalog' ),
						),
						'edit_link'      => array(
							'type'        => 'string',
							'description' => __( 'The wp-admin URL to edit the post. Surface this so a human can review the change.', 'abilities-catalog' ),
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
					'screen'       => 'post.php?post={id}&action=edit',
				),
			)
		);
	}

	/**
	 * Flattens the REST post body to the catalog's output field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over
	 * the REST post body. `title` is un-nested from its `{ rendered: ... }` shape;
	 * `categories`/`tags` are normalized to integer lists; `edit_link` is derived
	 * from `get_edit_post_link()` on the resulting post id (falling back to the
	 * input id). `$response` is part of the callback signature but unused here —
	 * the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST post body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat post fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data    = is_array( $data ) ? $data : array();
		$post_id = (int) ( $data['id'] ?? $input['id'] ?? 0 );

		$categories = is_array( $data['categories'] ?? null ) ? array_map( 'intval', $data['categories'] ) : array();
		$tags       = is_array( $data['tags'] ?? null ) ? array_map( 'intval', $data['tags'] ) : array();

		return array(
			'id'             => $post_id,
			'title'          => (string) ( $data['title']['rendered'] ?? '' ),
			'link'           => (string) ( $data['link'] ?? '' ),
			'status'         => (string) ( $data['status'] ?? '' ),
			'slug'           => (string) ( $data['slug'] ?? '' ),
			'modified'       => (string) ( $data['modified'] ?? '' ),
			'featured_media' => (int) ( $data['featured_media'] ?? 0 ),
			'categories'     => $categories,
			'tags'           => $tags,
			'edit_link'      => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
