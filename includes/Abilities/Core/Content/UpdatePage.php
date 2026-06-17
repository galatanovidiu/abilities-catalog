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
 * T1 write ability: `content/update-page`.
 *
 * Wraps `POST /wp/v2/pages/<id>` via `rest_do_request()` and returns the page's
 * id, link, status, and modified date. The `permission_callback` encodes the
 * catalog's object-level capabilities — `edit_post` on the target (mapped to
 * page caps via `map_meta_cap`), `publish_pages` when the requested status would
 * publish, and `edit_others_pages` when reassigning the page to another author.
 * The REST route re-checks every capability underneath (defense in depth) and
 * handles content sanitization.
 *
 * @since 0.2.0
 */
final class UpdatePage implements Ability {

	/**
	 * Page statuses that require the `publish_pages` capability.
	 *
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/update-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Page', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing page by ID. Only the provided fields change. Set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'The page ID to update.', 'abilities-catalog' ),
					),
					'title'          => array(
						'type'        => 'string',
						'description' => __( 'The page title.', 'abilities-catalog' ),
					),
					'content'        => array(
						'type'        => 'string',
						'description' => __( 'The page content as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->. Bare HTML is accepted but stored as a single classic block. Use templates/list-block-types to discover available blocks.', 'abilities-catalog' ),
					),
					'excerpt'        => array(
						'type'        => 'string',
						'description' => __( 'The page excerpt.', 'abilities-catalog' ),
					),
					'status'         => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
						'description' => __( 'The page status.', 'abilities-catalog' ),
					),
					'author'         => array(
						'type'        => 'integer',
						'description' => __( 'The author user ID. Setting another user requires the edit_others_pages capability.', 'abilities-catalog' ),
					),
					'slug'           => array(
						'type'        => 'string',
						'description' => __( 'The page slug.', 'abilities-catalog' ),
					),
					'date'           => array(
						'type'        => 'string',
						'description' => __( 'The publish date in site time (ISO 8601).', 'abilities-catalog' ),
					),
					'parent'         => array(
						'type'        => 'integer',
						'description' => __( 'The parent page ID.', 'abilities-catalog' ),
					),
					'menu_order'     => array(
						'type'        => 'integer',
						'description' => __( 'The page order value.', 'abilities-catalog' ),
					),
					'template'       => array(
						'type'        => 'string',
						'description' => __( 'A page-template slug registered by the active theme (not an arbitrary file name). Unknown values are rejected.', 'abilities-catalog' ),
					),
					'featured_media' => array(
						'type'        => 'integer',
						'description' => __( 'Attachment ID for the featured image.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'link', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The page ID.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The rendered page title.', 'abilities-catalog' ),
					),
					'link'      => array(
						'type'        => 'string',
						'description' => __( 'The page permalink.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting page status.', 'abilities-catalog' ),
					),
					'modified'  => array(
						'type'        => 'string',
						'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the page. Surface this so a human can review the change.', 'abilities-catalog' ),
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
	 * Permission check encoding the catalog capabilities for updating a page.
	 *
	 * Uses the type-level `edit_pages` capability as the coarse guard — an
	 * object-independent check so a missing or non-existent id is not masked as a
	 * permission failure. The object-level `edit_post` check and the specific
	 * `rest_post_invalid_id` (404) / `rest_cannot_edit` (403) errors come from the
	 * wrapped `POST /wp/v2/pages/<id>` route in `execute()`. Additionally requires
	 * `publish_pages` when the requested status would publish, and
	 * `edit_others_pages` when reassigning the page to another user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update pages of this type.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! current_user_can( 'edit_pages' ) ) {
			return false;
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$status = sanitize_key( (string) $input['status'] );
			if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( 'publish_pages' ) ) {
				return false;
			}
		}

		if ( ! empty( $input['author'] ) ) {
			$author = absint( $input['author'] );
			if ( $author !== get_current_user_id() && ! current_user_can( 'edit_others_pages' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The page's id, link, status, modified, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $id );

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Control fields are sanitized here.
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'date', 'template' ) as $field ) {
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

		if ( ! empty( $input['parent'] ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
		}

		if ( isset( $input['menu_order'] ) ) {
			// Core treats menu_order as a signed integer; preserve negatives.
			$request->set_param( 'menu_order', (int) $input['menu_order'] );
		}

		if ( ! empty( $input['featured_media'] ) ) {
			$request->set_param( 'featured_media', absint( $input['featured_media'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$page_id = (int) ( $data['id'] ?? $id );

		return array(
			'id'        => $page_id,
			'title'     => (string) ( $data['title']['rendered'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'modified'  => (string) ( $data['modified'] ?? '' ),
			'edit_link' => (string) get_edit_post_link( $page_id, 'raw' ),
		);
	}
}
