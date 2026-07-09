<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/list-block-pattern-categories`.
 *
 * Wraps `GET /wp/v2/block-patterns/categories` via the Abilities REST Adapter.
 * The input schema is DERIVED from the route (a no-input collection read). The
 * route's handler is `get_items`, so the adapter wraps its response in the
 * `{ items, total, total_pages }` collection envelope; {@see shapeOutput()} then
 * narrows it to the catalog's `{ items }` of `{ name, label, +description }`.
 * Permission delegates to the route's own check (no `require_permission` floor),
 * which already requires `edit_posts` (or the `edit_posts` cap of a public post
 * type) — the same floor the catalog imposed by hand, so visibility is unchanged.
 *
 * @since 0.5.0
 */
final class ListBlockPatternCategories implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-block-pattern-categories';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/block-patterns/categories',
				'method'          => 'GET',
				'label'           => __( 'List Block Pattern Categories', 'abilities-catalog' ),
				'description'     => __( 'Lists the registered block-pattern categories (the groupings used to organize block patterns). Pairs with the list-patterns ability to group patterns by category.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'name', 'label' ),
								'properties'           => array(
									'name'        => array(
										'type'        => 'string',
										'description' => __( 'The category slug (e.g. "header"). Use this to match patterns to their category.', 'abilities-catalog' ),
									),
									'label'       => array(
										'type'        => 'string',
										'description' => __( 'The human-readable category label (e.g. "Headers").', 'abilities-catalog' ),
									),
									'description' => array(
										'type'        => 'string',
										'description' => __( 'An optional description of the category.', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of registered block-pattern categories.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Narrows the collection envelope to the catalog's `{ items }` field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for this `get_items`
	 * route. Each row keeps only `name` and `label`, plus `description` when the REST
	 * row carries a non-empty one — matching the original closed output set. `$input`
	 * and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (associative array with an `items` list).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The narrowed `{ items }` collection.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$rows  = is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items = array();

		foreach ( $rows as $row ) {
			$row  = is_array( $row ) ? $row : array();
			$item = array(
				'name'  => (string) ( $row['name'] ?? '' ),
				'label' => (string) ( $row['label'] ?? '' ),
			);

			if ( isset( $row['description'] ) && '' !== $row['description'] ) {
				$item['description'] = (string) $row['description'];
			}

			$items[] = $item;
		}

		return array(
			'items' => $items,
		);
	}
}
