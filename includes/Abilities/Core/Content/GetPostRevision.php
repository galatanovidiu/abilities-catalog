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
 * Read ability: `og-content/get-post-revision`.
 *
 * Wraps `GET /wp/v2/posts/<parent>/revisions/<id>` via `rest_do_request()` and
 * shapes the response into a flat field set. The capability is object-level
 * `edit_post` on the parent post.
 *
 * @since 0.1.0
 */
final class GetPostRevision implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-post-revision';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Post Revision', 'abilities-catalog' ),
			'description'         => __( 'Returns a single post revision by parent post ID and revision ID.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent'  => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID. Use og-content/list-post-revisions to list revisions for the parent post.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'parent', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'parent', 'title', 'content', 'excerpt', 'date', 'modified' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID.', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The rendered revision title.', 'abilities-catalog' ),
					),
					'title_raw'   => array(
						'type'        => 'string',
						'description' => __( 'The stored (unrendered) revision title. Present only when context is "edit".', 'abilities-catalog' ),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __( 'The rendered revision content.', 'abilities-catalog' ),
					),
					'content_raw' => array(
						'type'        => 'string',
						'description' => __( 'The stored block markup of the revision content, for diffing or restoring. Present only when context is "edit".', 'abilities-catalog' ),
					),
					'excerpt'     => array(
						'type'        => 'string',
						'description' => __( 'The rendered revision excerpt.', 'abilities-catalog' ),
					),
					'excerpt_raw' => array(
						'type'        => 'string',
						'description' => __( 'The stored (unrendered) revision excerpt. Present only when context is "edit".', 'abilities-catalog' ),
					),
					'date'        => array(
						'type'        => 'string',
						'description' => __( 'The revision date in site time.', 'abilities-catalog' ),
					),
					'modified'    => array(
						'type'        => 'string',
						'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
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
	 * Permission check: delegated to the wrapped REST route.
	 *
	 * Reads through `GET /wp/v2/posts/<parent>/revisions/<id>`, whose permission
	 * check enforces `edit_post` on the parent post. Deferring to the route lets
	 * `execute()` surface its specific error (`rest_post_invalid_parent` 404 for a
	 * missing parent, `rest_post_invalid_id` 404 for an invalid or non-revision id,
	 * `rest_revision_parent_id_mismatch` 404 when the revision is not under that
	 * parent, `rest_cannot_read` 403 on denial) instead of masking a missing parent
	 * or revision as a permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; the wrapped route is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat revision fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$parent  = absint( $input['parent'] );
		$id      = absint( $input['id'] );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $parent . '/revisions/' . $id );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$result = array(
			'id'       => (int) ( $data['id'] ?? $id ),
			'parent'   => (int) ( $data['parent'] ?? $parent ),
			'title'    => (string) ( $data['title']['rendered'] ?? '' ),
			'content'  => (string) ( $data['content']['rendered'] ?? '' ),
			'excerpt'  => (string) ( $data['excerpt']['rendered'] ?? '' ),
			'date'     => (string) ( $data['date'] ?? '' ),
			'modified' => (string) ( $data['modified'] ?? '' ),
		);

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
