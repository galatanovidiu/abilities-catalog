<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `templates/list-synced-patterns`.
 *
 * Wraps `GET /wp/v2/blocks` via `rest_do_request()` and shapes the result. A
 * synced pattern is a user-created reusable block stored as a `wp_block` post;
 * editing one updates every place it is inserted. Returns a flattened list
 * (id, title, slug, status, modified) so an agent can find a synced pattern's id
 * and then read it with `templates/get-pattern`. This is the user pattern library,
 * distinct from `templates/list-patterns` (the read-only registered pattern
 * registry). Read-only.
 *
 * @since 0.5.0
 */
final class ListSyncedPatterns implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/list-synced-patterns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Synced Patterns', 'abilities-catalog' ),
			'description'         => __( 'Lists the user-created synced patterns (reusable blocks, post type "wp_block"). Returns id, title, slug, and status so the pattern can then be read with the get-pattern ability. This is the editable user pattern library, not the read-only registered pattern registry.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'context'  => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'The request context. Defaults to "view".', 'abilities-catalog' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'The page of results to return.', 'abilities-catalog' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'The number of synced patterns per page (1-100).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'title', 'status' ),
							'properties'           => array(
								'id'       => array(
									'type'        => 'integer',
									'description' => __( 'The synced pattern (wp_block) post ID.', 'abilities-catalog' ),
								),
								'title'    => array(
									'type'        => 'string',
									'description' => __( 'The synced pattern title.', 'abilities-catalog' ),
								),
								'slug'     => array(
									'type'        => 'string',
									'description' => __( 'The synced pattern slug.', 'abilities-catalog' ),
								),
								'status'   => array(
									'type'        => 'string',
									'description' => __( 'The synced pattern post status.', 'abilities-catalog' ),
								),
								'modified' => array(
									'type'        => 'string',
									'description' => __( 'The last-modified date (site time).', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __( 'The list of synced patterns.', 'abilities-catalog' ),
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
	 * Permission check: the `wp_block` post type's `edit_posts` capability.
	 *
	 * Resolved dynamically from the post type object so the gate matches the
	 * blocks (posts) controller and is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list synced patterns.
	 */
	public function hasPermission( $input = null ): bool {
		$post_type = get_post_type_object( 'wp_block' );
		if ( null === $post_type ) {
			return false;
		}

		return current_user_can( $post_type->cap->edit_posts );
	}

	/**
	 * Executes the ability by dispatching the internal REST request and shaping the result.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped collection, or the REST error.
	 */
	public function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'GET', '/wp/v2/blocks' );
		$request->set_param( 'context', isset( $input['context'] ) ? sanitize_key( (string) $input['context'] ) : 'view' );
		$request->set_param( 'page', isset( $input['page'] ) ? absint( $input['page'] ) : 1 );
		$request->set_param( 'per_page', isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 10 );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
			$title = $row['title'] ?? '';
			if ( is_array( $title ) ) {
				$title = $title['rendered'] ?? ( $title['raw'] ?? '' );
			}

			$items[] = array(
				'id'       => (int) ( $row['id'] ?? 0 ),
				'title'    => (string) $title,
				'slug'     => (string) ( $row['slug'] ?? '' ),
				'status'   => (string) ( $row['status'] ?? '' ),
				'modified' => (string) ( $row['modified'] ?? '' ),
			);
		}

		return array(
			'items' => $items,
		);
	}
}
