<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use GalatanOvidiu\AbilitiesCatalog\Support\UserListShaper;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `users/list-users`.
 *
 * Wraps `GET /wp/v2/users` via `rest_do_request()` and returns flat summary rows
 * (via {@see UserListShaper}) plus the total counts from the REST response
 * headers. The full record lives behind `users/get-user`. Encodes the catalog
 * capability `list_users`. Read-only.
 *
 * @since 0.1.0
 */
final class ListUsers implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/list-users';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Users', 'abilities-catalog' ),
			'description'         => __( 'Returns a paginated list of users, with optional search, role, and capability filters.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'page'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Current page of the collection.', 'abilities-catalog' ),
					),
					'per_page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Maximum number of users to return per page.', 'abilities-catalog' ),
					),
					'search'       => array(
						'type'        => 'string',
						'description' => __( 'Limit results to those matching a search string.', 'abilities-catalog' ),
					),
					'roles'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Limit results to users with one or more of the given roles.', 'abilities-catalog' ),
					),
					'capabilities' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Limit results to users matching one or more of the given capabilities.', 'abilities-catalog' ),
					),
					'orderby'      => array(
						'type'        => 'string',
						'enum'        => array( 'id', 'include', 'name', 'registered_date', 'slug', 'include_slugs', 'email', 'url' ),
						'default'     => 'name',
						'description' => __( 'Sort collection by user attribute.', 'abilities-catalog' ),
					),
					'order'        => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'default'     => 'asc',
						'description' => __( 'Order sort attribute ascending or descending.', 'abilities-catalog' ),
					),
					'context'      => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items'       => array(
						'type'        => 'array',
						'items'       => UserListShaper::userItemSchema(),
						'description' => __( 'The list of users as flat summary rows. Use users/get-user for the full single user.', 'abilities-catalog' ),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'Total number of users across all pages.', 'abilities-catalog' ),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of pages available.', 'abilities-catalog' ),
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
	 * Permission check: the current user may list users.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user has the `list_users` capability.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'list_users' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The user collection, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$request->set_param( 'context', $context );

		foreach ( array( 'page', 'per_page' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$request->set_param( $key, absint( $input[ $key ] ) );
		}

		if ( ! empty( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}

		foreach ( array( 'roles', 'capabilities' ) as $key ) {
			if ( empty( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) {
				continue;
			}

			$request->set_param( $key, array_map( 'strval', $input[ $key ] ) );
		}

		foreach ( array( 'orderby', 'order' ) as $key ) {
			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			$request->set_param( $key, (string) $input[ $key ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$headers = $response->get_headers();

		$data  = is_array( $data ) ? $data : array();
		$items = array_map(
			static fn ( $item ): array => UserListShaper::userSummary( is_array( $item ) ? $item : array() ),
			array_values( $data )
		);

		return array(
			'items'       => $items,
			'total'       => (int) ( $headers['X-WP-Total'] ?? count( $data ) ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}
}
