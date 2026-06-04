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
 * Read ability: `content/list-post-revisions`.
 *
 * Wraps `GET /wp/v2/posts/<parent>/revisions` via `rest_do_request()`. The
 * capability is object-level `edit_post` on the parent post.
 *
 * @since 0.1.0
 */
final class ListPostRevisions implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/list-post-revisions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Post Revisions', 'abilities-catalog' ),
			'description'         => __( 'Lists the saved revisions of a post by its parent post ID.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent'  => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'parent' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items'       => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'description' => __( 'The list of revisions.', 'abilities-catalog' ),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'Total number of revisions.', 'abilities-catalog' ),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of result pages available.', 'abilities-catalog' ),
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
	 * Reads through `GET /wp/v2/posts/<parent>/revisions`, whose permission check
	 * enforces `edit_post` on the parent post. Deferring to the route lets
	 * `execute()` surface its specific error (`rest_post_invalid_parent` 404,
	 * `rest_cannot_read` 403) instead of masking a missing parent as a permission
	 * failure.
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
	 * @return array<string,mixed>|\WP_Error The collection and totals, or the REST error.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$parent = absint( $input['parent'] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $parent . '/revisions' );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$items   = rest_get_server()->response_to_data( $response, false );
		$headers = $response->get_headers();

		return array(
			'items'       => is_array( $items ) ? $items : array(),
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}
}
