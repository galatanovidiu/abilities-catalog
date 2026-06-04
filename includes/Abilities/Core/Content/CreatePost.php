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
 * Reference T1 write ability: `content/create-post`.
 *
 * Wraps `POST /wp/v2/posts` via `rest_do_request()` and returns the new post's
 * id, link, and status. Establishes the per-ability pattern for write fan-out:
 * an input-aware `permission_callback` that encodes the catalog's capabilities
 * exactly — `edit_posts` to author a draft, `publish_posts` to publish, and
 * `edit_others_posts` to set another user as author — and write annotations
 * (`readonly:false, destructive:false, idempotent:false`) so the run controller
 * routes the call as POST. The REST route re-checks every capability underneath
 * (defense in depth) and handles content sanitization.
 *
 * @since 0.2.0
 */
final class CreatePost implements Ability {

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
		return 'content/create-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Post', 'abilities-catalog' ),
			'description'         => __( 'Creates a new post. Defaults to a draft; set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'          => array(
						'type'        => 'string',
						'description' => __( 'The post title.', 'abilities-catalog' ),
					),
					'content'        => array(
						'type'        => 'string',
						'description' => __( 'The post content as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->. Bare HTML is accepted but stored as a single classic block. Use templates/list-block-types to discover available blocks.', 'abilities-catalog' ),
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
						'description' => __( 'The publish date in site time (ISO 8601).', 'abilities-catalog' ),
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
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'link', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The new post ID.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The rendered post title.', 'abilities-catalog' ),
					),
					'link'      => array(
						'type'        => 'string',
						'description' => __( 'The post permalink.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting post status.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the post. Surface this so a human can review the draft.', 'abilities-catalog' ),
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
			),
		);
	}

	/**
	 * Permission check encoding the catalog capabilities for creating a post.
	 *
	 * Requires `edit_posts`; additionally `publish_posts` when the requested
	 * status would publish, and `edit_others_posts` when authoring as another
	 * user. Per-term assignment capabilities are enforced by the REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the requested post.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$status = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'draft';
		if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( 'publish_posts' ) ) {
			return false;
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
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new post's id, link, status, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Control fields are sanitized here.
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'date' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$request->set_param( 'status', sanitize_key( (string) $input['status'] ) );
		}

		if ( ! empty( $input['author'] ) ) {
			$request->set_param( 'author', absint( $input['author'] ) );
		}

		if ( ! empty( $input['featured_media'] ) ) {
			$request->set_param( 'featured_media', absint( $input['featured_media'] ) );
		}

		foreach ( array( 'categories', 'tags' ) as $taxonomy_field ) {
			if ( empty( $input[ $taxonomy_field ] ) || ! is_array( $input[ $taxonomy_field ] ) ) {
				continue;
			}

			$request->set_param( $taxonomy_field, array_map( 'absint', $input[ $taxonomy_field ] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$post_id = (int) ( $data['id'] ?? 0 );

		return array(
			'id'        => $post_id,
			'title'     => (string) ( $data['title']['rendered'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'edit_link' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
