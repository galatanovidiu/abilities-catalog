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
			'output_schema'       => array(
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
	 * `content/get-post` reads through `GET /wp/v2/posts/<id>`, whose own
	 * permission check enforces `read_post` on the object — granting public access
	 * to published public posts (including anonymous callers) and denying private
	 * or password-protected ones. Doing the object-level check here instead would
	 * mask a missing or non-existent id as "permission denied"; deferring to the
	 * route lets `execute()` surface the route's specific error
	 * (`rest_post_invalid_id` 404, `rest_forbidden` 403) via {@see RestError::from()}.
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
	 * @return array<string,mixed>|\WP_Error Flat post fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = (int) ( $input['id'] ?? 0 );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$request->set_param( 'context', $context );
		if ( ! empty( $input['password'] ) ) {
			$request->set_param( 'password', $input['password'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$result = array(
			'id'                 => (int) ( $data['id'] ?? $id ),
			'title'              => (string) ( $data['title']['rendered'] ?? '' ),
			'content'            => (string) ( $data['content']['rendered'] ?? '' ),
			'excerpt'            => (string) ( $data['excerpt']['rendered'] ?? '' ),
			'slug'               => (string) ( $data['slug'] ?? '' ),
			'status'             => (string) ( $data['status'] ?? get_post_status( $id ) ),
			'author'             => (int) ( $data['author'] ?? 0 ),
			'link'               => (string) ( $data['link'] ?? '' ),
			'password_protected' => (bool) ( $data['content']['protected'] ?? $data['excerpt']['protected'] ?? false ),
			'date'               => (string) ( $data['date'] ?? '' ),
			'modified'           => (string) ( $data['modified'] ?? '' ),
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
