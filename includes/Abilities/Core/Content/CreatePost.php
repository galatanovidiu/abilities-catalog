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
 * Write ability: `og-content/create-post`.
 *
 * Wraps `POST /wp/v2/posts` via the Abilities REST Adapter. The input schema is
 * OVERRIDDEN to the catalog's curated field set (title/content/excerpt/status/
 * author/slug/date/categories/tags/featured_media), so the raw REST fields the
 * catalog does not want exposed (`author_ip`, `meta`, `date_gmt`, etc.) stay
 * hidden. The output is OVERRIDDEN to the catalog's flat 9-field set through
 * {@see shapeOutput()}. Permission delegates to the route's own create check (no
 * `require_permission` floor is set): the route enforces `create_posts`,
 * `publish_posts`, and `edit_others_posts` underneath, equivalent to the
 * catalog's old baseline, so a denial surfaces as the real REST error
 * (`rest_cannot_create`) through `execute()`.
 *
 * @since 0.2.0
 */
final class CreatePost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/create-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/posts',
				'method'          => 'POST',
				'label'           => __( 'Create Post', 'abilities-catalog' ),
				'description'     => __( 'Creates a new post. Provide at least one of title, content, or excerpt; core rejects an otherwise empty post. Defaults to a draft; set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
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
							'default'     => 'draft',
							'description' => __( 'The post status. Defaults to "draft".', 'abilities-catalog' ),
						),
						'author'         => array(
							'type'        => 'integer',
							'description' => __( 'The author user ID. Setting another user requires the edit_others_posts capability.', 'abilities-catalog' ),
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'The post slug.', 'abilities-catalog' ),
						),
						'date'           => array(
							'type'        => 'string',
							'format'      => 'date-time',
							'description' => __( 'The publish date in site time, as an ISO 8601 date-time string (e.g. 2024-01-31T13:45:00).', 'abilities-catalog' ),
						),
						'categories'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Category term IDs to assign.', 'abilities-catalog' ),
						),
						'tags'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Tag term IDs to assign.', 'abilities-catalog' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => __( 'Attachment ID for the featured image.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status', 'link', 'edit_link' ),
					'properties'           => array(
						'id'             => array(
							'type'        => 'integer',
							'description' => __( 'The new post ID.', 'abilities-catalog' ),
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
						'featured_media' => array(
							'type'        => 'integer',
							'description' => __( 'The resulting featured image attachment ID, or 0 if none was set.', 'abilities-catalog' ),
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
							'description' => __( 'The wp-admin URL to edit the post. Surface this so a human can review the draft.', 'abilities-catalog' ),
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
				),
			)
		);
	}

	/**
	 * Flattens the REST post body to the catalog's 9-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST post body. `title` is un-nested from its `{ rendered: ... }` shape;
	 * `categories`/`tags` are coerced to integer arrays; `edit_link` is derived from
	 * the new post ID via `get_edit_post_link()` so a human can review the draft.
	 * `$input` and `$response` are part of the callback signature but unused here —
	 * the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST post body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat post fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data    = is_array( $data ) ? $data : array();
		$post_id = (int) ( $data['id'] ?? 0 );

		$categories = is_array( $data['categories'] ?? null ) ? array_map( 'intval', $data['categories'] ) : array();
		$tags       = is_array( $data['tags'] ?? null ) ? array_map( 'intval', $data['tags'] ) : array();

		return array(
			'id'             => $post_id,
			'title'          => (string) ( $data['title']['rendered'] ?? '' ),
			'link'           => (string) ( $data['link'] ?? '' ),
			'status'         => (string) ( $data['status'] ?? '' ),
			'slug'           => (string) ( $data['slug'] ?? '' ),
			'featured_media' => (int) ( $data['featured_media'] ?? 0 ),
			'categories'     => $categories,
			'tags'           => $tags,
			'edit_link'      => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
