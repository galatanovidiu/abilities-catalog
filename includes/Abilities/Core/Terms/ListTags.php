<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-terms/list-tags`.
 *
 * Wraps `GET /wp/v2/tags` via the Abilities REST Adapter and returns the matching
 * post-tag terms in the adapter's `{ items, total, total_pages }` envelope (totals
 * from the REST response headers). Permission delegates to the route's own check
 * (no `require_permission` floor): tag reads are public in `view`, and the route
 * requires `manage_post_tags` for `edit`. No output reshaping is needed — each item
 * is the raw term object, as before.
 *
 * @since 0.1.0
 */
final class ListTags implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/list-tags';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'         => '/wp/v2/tags',
				'method'        => 'GET',
				'label'         => __( 'List Tags', 'abilities-catalog' ),
				'description'   => __( 'Returns a paginated list of post-tag terms, optionally filtered by search string. Each item is a raw tag term object; the result includes "total" and "total_pages" counts. Use this for the "post_tag" taxonomy; for the "category" taxonomy use og-terms/list-categories, and for an arbitrary taxonomy use og-terms/list-terms.', 'abilities-catalog' ),
				'category'      => 'og-core-terms',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'description' => __( 'Limit results to terms matching a search string.', 'abilities-catalog' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'description' => __( 'Number of terms to return per page.', 'abilities-catalog' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page number of the result set.', 'abilities-catalog' ),
						),
						'orderby'  => array(
							'type'        => 'string',
							'enum'        => array( 'id', 'name', 'slug', 'count', 'term_group', 'include', 'description' ),
							'description' => __( 'Field to sort the terms by.', 'abilities-catalog' ),
						),
						'order'    => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => __( 'Sort direction.', 'abilities-catalog' ),
						),
						'context'  => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'total', 'total_pages' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'description' => __( 'The matching post-tag terms.', 'abilities-catalog' ),
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
				'meta'          => array(
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
}
