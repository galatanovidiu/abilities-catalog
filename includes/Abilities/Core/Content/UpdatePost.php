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
 * T1 write ability: `og-content/update-post`.
 *
 * Wraps `POST /wp/v2/posts/<id>` via `rest_do_request()` and returns the post's
 * id, link, status, and modified date. The `permission_callback` enforces the
 * coarse type-level `edit_posts` capability as the catalog guard, plus
 * `publish_posts` when the requested status would publish and
 * `edit_others_posts` when reassigning the post to another author. The
 * object-level `edit_post` check on the target post is delegated to the wrapped
 * REST route, which re-checks every capability underneath (defense in depth)
 * and handles content sanitization.
 *
 * @since 0.2.0
 */
final class UpdatePost implements Ability {

	/**
	 * Post statuses that require the `publish_posts` capability.
	 *
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array( 'publish', 'future', 'private' );

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
		return array(
			'label'               => __( 'Update Post', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing post by ID. Only the provided fields change. Set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check encoding the catalog capabilities for updating a post.
	 *
	 * Uses the type-level `edit_posts` capability as the coarse guard — an
	 * object-independent check so a missing or non-existent id is not masked as a
	 * permission failure. The object-level `edit_post` check and the specific
	 * `rest_post_invalid_id` (404) / `rest_cannot_edit` (403) errors come from the
	 * wrapped `POST /wp/v2/posts/<id>` route in `execute()`. Additionally requires
	 * `publish_posts` when the requested status would publish, and
	 * `edit_others_posts` when reassigning the post to another user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update posts of this type.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$status = sanitize_key( (string) $input['status'] );
			if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( 'publish_posts' ) ) {
				return false;
			}
		}

		if ( ! empty( $input['author'] ) ) {
			$author = absint( $input['author'] );
			if ( $author !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post's id, link, status, modified, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Forward whenever the key is present,
		// including '' so the caller can blank a title or excerpt. Core writes
		// empty strings on update.
		foreach ( array( 'title', 'content', 'excerpt', 'slug' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		// An empty date string is invalid input: core resets the publish date only
		// on date=null, which a date-time string schema cannot express. Skip ''.
		if ( ! empty( $input['date'] ) ) {
			$request->set_param( 'date', (string) $input['date'] );
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$request->set_param( 'status', sanitize_key( (string) $input['status'] ) );
		}

		if ( ! empty( $input['author'] ) ) {
			$request->set_param( 'author', absint( $input['author'] ) );
		}

		// Forward whenever present, including 0 so the caller can detach the
		// current featured image.
		if ( array_key_exists( 'featured_media', $input ) ) {
			$request->set_param( 'featured_media', absint( $input['featured_media'] ) );
		}

		// Forward whenever present, including [] so the caller can clear all terms.
		foreach ( array( 'categories', 'tags' ) as $taxonomy_field ) {
			if ( ! array_key_exists( $taxonomy_field, $input ) || ! is_array( $input[ $taxonomy_field ] ) ) {
				continue;
			}

			$request->set_param( $taxonomy_field, array_map( 'absint', $input[ $taxonomy_field ] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$post_id = (int) ( $data['id'] ?? $id );

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
