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
 * Read ability: `og-content/get-post`.
 *
 * Wraps `GET /wp/v2/posts/<id>` via the Abilities REST Adapter. The input schema
 * is kept curated (a required `id`, plus `context` `view`/`edit` and `password`)
 * so the path capture, context, and password stay tidy. The output is OVERRIDDEN
 * to the catalog's flat field set through {@see shapeOutput()}, which un-nests
 * `title`/`content`/`excerpt` and adds the `*_raw` fields only when core supplied
 * them (`edit` context). Permission delegates to the route's own check (no
 * `require_permission` floor is set), so visibility follows REST — granting public
 * access to published public posts (including anonymous callers) and surfacing the
 * route's specific error (`rest_post_invalid_id` 404, `rest_forbidden` 403) on
 * `execute()`.
 *
 * @since 0.1.0
 */
final class GetPost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/posts/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Post', 'abilities-catalog' ),
				'description'     => __( 'Returns a single post by ID, including its rendered title, content, and excerpt.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The post ID.', 'abilities-catalog' ),
						),
						'context'  => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
						'password' => array(
							'type'        => 'string',
							'description' => __( 'Password for a password-protected post.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'title', 'status', 'link' ),
					'properties'           => array(
						'id'                 => array(
							'type'        => 'integer',
							'description' => __( 'The post ID.', 'abilities-catalog' ),
						),
						'title'              => array(
							'type'        => 'string',
							'description' => __( 'The rendered post title.', 'abilities-catalog' ),
						),
						'title_raw'          => array(
							'type'        => 'string',
							'description' => __( 'The stored (unrendered) post title. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'content'            => array(
							'type'        => 'string',
							'description' => __( 'The rendered post content.', 'abilities-catalog' ),
						),
						'content_raw'        => array(
							'type'        => 'string',
							'description' => __( 'The stored block markup of the post content, for diffing or restoring. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'excerpt'            => array(
							'type'        => 'string',
							'description' => __( 'The rendered post excerpt.', 'abilities-catalog' ),
						),
						'excerpt_raw'        => array(
							'type'        => 'string',
							'description' => __( 'The stored (unrendered) post excerpt. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'slug'               => array(
							'type'        => 'string',
							'description' => __( 'The post slug.', 'abilities-catalog' ),
						),
						'status'             => array(
							'type'        => 'string',
							'description' => __( 'The post status.', 'abilities-catalog' ),
						),
						'author'             => array(
							'type'        => 'integer',
							'description' => __( 'The author user ID.', 'abilities-catalog' ),
						),
						'link'               => array(
							'type'        => 'string',
							'description' => __( 'The post URL (may be a non-public draft/preview URL for non-published posts).', 'abilities-catalog' ),
						),
						'password_protected' => array(
							'type'        => 'boolean',
							'description' => __( 'True when the post is password-protected. The rendered content/excerpt are empty unless the correct password is supplied.', 'abilities-catalog' ),
						),
						'date'               => array(
							'type'        => 'string',
							'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
						),
						'modified'           => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
						),
						'featured_media'     => array(
							'type'        => 'integer',
							'description' => __( 'The featured image attachment ID, or 0 if the post has no featured image. Read the image itself with og-media/get-media.', 'abilities-catalog' ),
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
	 * Flattens the REST post body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST post body. `title`/`content`/`excerpt` are un-nested from their
	 * `{ rendered: ... }` shape; `password_protected` is read from the body's
	 * `protected` flags. The `*_raw` fields are added only when core supplied them —
	 * core includes `title.raw`/`content.raw`/`excerpt.raw` only in `edit` context, so
	 * in `view` context they are omitted rather than invented, keeping the contract
	 * honest per context. `$input` and `$response` are part of the callback signature
	 * but unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST post body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat post fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$result = array(
			'id'                 => (int) ( $data['id'] ?? 0 ),
			'title'              => (string) ( $data['title']['rendered'] ?? '' ),
			'content'            => (string) ( $data['content']['rendered'] ?? '' ),
			'excerpt'            => (string) ( $data['excerpt']['rendered'] ?? '' ),
			'slug'               => (string) ( $data['slug'] ?? '' ),
			'status'             => (string) ( $data['status'] ?? '' ),
			'author'             => (int) ( $data['author'] ?? 0 ),
			'link'               => (string) ( $data['link'] ?? '' ),
			'password_protected' => (bool) ( $data['content']['protected'] ?? $data['excerpt']['protected'] ?? false ),
			'date'               => (string) ( $data['date'] ?? '' ),
			'modified'           => (string) ( $data['modified'] ?? '' ),
			'featured_media'     => (int) ( $data['featured_media'] ?? 0 ),
		);

		return $this->withRawFields( $result, $data );
	}

	/**
	 * Adds the stored (raw) block-markup fields when core supplied them.
	 *
	 * Core only includes `title.raw`/`content.raw`/`excerpt.raw` in `edit` context.
	 * In `view` context those keys are absent, so the `*_raw` fields are omitted
	 * rather than invented — keeping the output contract honest per context.
	 *
	 * @param array<string,mixed> $result The flat result being built.
	 * @param array<string,mixed> $data   The REST response data.
	 * @return array<string,mixed> The result with raw fields added when available.
	 */
	private function withRawFields( array $result, array $data ): array {
		if ( isset( $data['title']['raw'] ) ) {
			$result['title_raw'] = (string) $data['title']['raw'];
		}
		if ( isset( $data['content']['raw'] ) ) {
			$result['content_raw'] = (string) $data['content']['raw'];
		}
		if ( isset( $data['excerpt']['raw'] ) ) {
			$result['excerpt_raw'] = (string) $data['excerpt']['raw'];
		}

		return $result;
	}
}
