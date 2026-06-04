<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ContentListShaper;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `content/list-cpt-items`.
 *
 * Generic collection reader keyed by `post_type`. Resolves the type's REST base
 * and wraps `GET /wp/v2/<rest_base>` via `rest_do_request()`. Rejects post types
 * that are not exposed in REST.
 *
 * @since 0.1.0
 */
final class ListCptItems implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/list-cpt-items';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Custom Post Type Items', 'abilities-catalog' ),
			'description'         => __( 'Lists items of any REST-enabled post type with search, status, ordering, and pagination filters.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'The post type slug to list.', 'abilities-catalog' ),
					),
					'search'    => array(
						'type'        => 'string',
						'description' => __( 'Limit results to those matching a search term.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'Limit results to a post status.', 'abilities-catalog' ),
					),
					'per_page'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
					),
					'page'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
					),
					'orderby'   => array(
						'type'        => 'string',
						'description' => __( 'Field to sort by.', 'abilities-catalog' ),
					),
					'order'     => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Sort direction.', 'abilities-catalog' ),
					),
					'context'   => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_type' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items'       => array(
						'type'        => 'array',
						'items'       => ContentListShaper::postItemSchema(),
						'description' => __( 'The list of items as flat summary rows. Use content/get-cpt-item for a single item body.', 'abilities-catalog' ),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'Total number of items matching the query.', 'abilities-catalog' ),
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
	 * Permission check: `edit_posts` cap of the type for edit-context; otherwise
	 * any logged-in user.
	 *
	 * For an unknown or non-REST post type it returns true so `execute()` can
	 * surface the specific `invalid_post_type` (400) error instead of masking it as
	 * a permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list the type's items.
	 */
	public function hasPermission( $input ): bool {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return true;
		}

		$context = $input['context'] ?? 'view';
		if ( 'edit' === $context ) {
			return current_user_can( $obj->cap->edit_posts );
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and totals, or an error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'The requested post type does not exist or is not available in REST.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$rest_base = $obj->rest_base ?: $post_type;

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . $rest_base );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		if ( isset( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		if ( isset( $input['status'] ) ) {
			$request->set_param( 'status', (string) $input['status'] );
		}
		if ( isset( $input['per_page'] ) ) {
			$request->set_param( 'per_page', absint( $input['per_page'] ) );
		}
		if ( isset( $input['page'] ) ) {
			$request->set_param( 'page', absint( $input['page'] ) );
		}
		if ( isset( $input['orderby'] ) ) {
			$request->set_param( 'orderby', (string) $input['orderby'] );
		}
		if ( isset( $input['order'] ) ) {
			$request->set_param( 'order', (string) $input['order'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$items   = rest_get_server()->response_to_data( $response, false );
		$headers = $response->get_headers();
		$rows    = is_array( $items ) ? array_map( array( ContentListShaper::class, 'postSummary' ), $items ) : array();

		return array(
			'items'       => $rows,
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}
}
