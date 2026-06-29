<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-terms/list-categories`.
 *
 * Wraps `GET /wp/v2/categories` via the Abilities REST Adapter and returns the
 * matching category terms in the adapter's `{ items, total, total_pages }`
 * envelope (totals from the REST response headers). The bare route is public for
 * category reads, so a `require_permission` floor keeps the catalog's original
 * cap (logged-in for view; `manage_categories` for edit). No output reshaping is
 * needed — each item is the raw term object, as before.
 *
 * @since 0.1.0
 */
final class ListCategories implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/list-categories';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/categories',
				'method'             => 'GET',
				'label'              => __( 'List Categories', 'abilities-catalog' ),
				'description'        => __( 'Returns category terms, optionally filtered and paginated.', 'abilities-catalog' ),
				'category'           => 'og-core-terms',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to terms matching a search string.', 'abilities-catalog' ),
						),
						'parent'     => array(
							'type'        => 'integer',
							'description' => __( 'Limit results to terms with the given parent term ID. Pass 0 to return only top-level categories. Discover IDs with og-terms/list-categories.', 'abilities-catalog' ),
						),
						'per_page'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => __( 'Number of terms to return per page.', 'abilities-catalog' ),
						),
						'page'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page number of the result set.', 'abilities-catalog' ),
						),
						'orderby'    => array(
							'type'        => 'string',
							'enum'        => array( 'id', 'name', 'slug', 'count', 'term_group', 'include', 'description' ),
							'description' => __( 'Field to sort the terms by.', 'abilities-catalog' ),
						),
						'order'      => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => __( 'Sort direction.', 'abilities-catalog' ),
						),
						'hide_empty' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to hide terms that are not assigned to any post.', 'abilities-catalog' ),
						),
						'context'    => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'description' => __( 'The matching category terms.', 'abilities-catalog' ),
							'items'       => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of matching terms.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of result pages.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'require_permission' => array( $this, 'requirePermission' ),
				'meta'               => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission floor: term reads require an authenticated user; edit-context
	 * additionally requires `manage_categories`.
	 *
	 * Mirrors the catalog's original cap (the bare route is public for category
	 * reads). The route's own check still runs at dispatch.
	 *
	 * @param mixed $input The raw ability input.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		$input   = is_array( $input ) ? $input : array();
		$context = $input['context'] ?? 'view';

		if ( 'edit' === $context ) {
			return current_user_can( 'manage_categories' );
		}

		return is_user_logged_in();
	}
}
