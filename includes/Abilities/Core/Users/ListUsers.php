<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\UserListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-users/list-users`.
 *
 * Wraps `GET /wp/v2/users` via the Abilities REST Adapter and returns flat summary
 * rows (via {@see UserListShaper} in {@see shapeOutput()}) plus the total counts
 * from the REST response headers. The full record lives behind `og-users/get-user`.
 * Permission delegates to the route's own check (no `require_permission` floor):
 * the route serves public authors in `view` and requires `list_users` for `edit`
 * context, role/capability filters, and email/registered_date ordering. Read-only.
 *
 * @since 0.1.0
 */
final class ListUsers implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/list-users';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/users',
				'method'          => 'GET',
				'label'           => __( 'List Users', 'abilities-catalog' ),
				'description'     => __( 'Returns a paginated list of users, with optional search, role, and capability filters.', 'abilities-catalog' ),
				'category'        => 'og-core-users',
				'input_schema'    => array(
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
							'description' => __( 'Limit results to users with one or more of the given roles. Pass role slugs or names (e.g. "editor"), not capabilities.', 'abilities-catalog' ),
						),
						'capabilities' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Limit results to users matching one or more of the given primitive capabilities (e.g. "edit_posts"). Does not work for meta capabilities mapped per-object (e.g. "edit_post").', 'abilities-catalog' ),
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
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access). In "edit" context, rows the caller cannot edit are dropped from items, but total/total_pages still reflect the underlying REST query, so items may be shorter than total suggests.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'total', 'total_pages' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => UserListShaper::userItemSchema(),
							'description' => __( 'The list of users as flat summary rows. Use og-users/get-user for the full single user.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of users across all pages, from the underlying REST query. In "edit" context this counts the full result set, not only the rows the caller may view, so it may exceed the number of items returned.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages available, from the underlying REST query. In "edit" context this is computed from the full result set, not only the rows the caller may view.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'       => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'abilities_catalog' => array(
						'scope' => 'site',
					),
					'show_in_rest'      => true,
				),
			)
		);
	}

	/**
	 * Flattens the collection envelope into the catalog's summary-row shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Each row is flattened by
	 * {@see UserListShaper::userSummary()}; the totals carry through unchanged.
	 * `$input` and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat user summary rows and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			$rows[] = UserListShaper::userSummary( is_array( $item ) ? $item : array() );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? count( $rows ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
