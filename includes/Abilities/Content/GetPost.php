<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reference T1 read ability: `content/get-post`.
 *
 * Wraps `GET /wp/v2/posts/<id>` via `rest_do_request()` and shapes the response
 * into a flat field set. Establishes the per-ability pattern for the fan-out:
 * an input-aware `permission_callback` that encodes the catalog's object-level
 * capability (`read_post`), a REST wrapper that does not reimplement core logic,
 * and an output mapped to a strict schema.
 *
 * @since 0.1.0
 */
final class GetPost implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/get-post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Post', 'abilities-catalog' ),
			'description'         => __( 'Returns a single post by ID, including its rendered title, content, and excerpt.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
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
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'title', 'status', 'link' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __( 'The rendered post title.', 'abilities-catalog' ),
					),
					'content'  => array(
						'type'        => 'string',
						'description' => __( 'The rendered post content.', 'abilities-catalog' ),
					),
					'excerpt'  => array(
						'type'        => 'string',
						'description' => __( 'The rendered post excerpt.', 'abilities-catalog' ),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => __( 'The post status.', 'abilities-catalog' ),
					),
					'author'   => array(
						'type'        => 'integer',
						'description' => __( 'The author user ID.', 'abilities-catalog' ),
					),
					'link'     => array(
						'type'        => 'string',
						'description' => __( 'The public permalink.', 'abilities-catalog' ),
					),
					'date'     => array(
						'type'        => 'string',
						'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
					),
					'modified' => array(
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
	 * Permission check: read access to the requested post (object-level).
	 *
	 * Encodes the catalog capability for `content/get-post` — `read_post` on the
	 * object — which also resolves to public access for published public posts.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the post.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'read_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat post fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$request->set_param( 'context', $context );
		if ( ! empty( $input['password'] ) ) {
			$request->set_param( 'password', $input['password'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'       => (int) ( $data['id'] ?? $id ),
			'title'    => (string) ( $data['title']['rendered'] ?? '' ),
			'content'  => (string) ( $data['content']['rendered'] ?? '' ),
			'excerpt'  => (string) ( $data['excerpt']['rendered'] ?? '' ),
			'status'   => (string) ( $data['status'] ?? get_post_status( $id ) ),
			'author'   => (int) ( $data['author'] ?? 0 ),
			'link'     => (string) ( $data['link'] ?? '' ),
			'date'     => (string) ( $data['date'] ?? '' ),
			'modified' => (string) ( $data['modified'] ?? '' ),
		);
	}
}
