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
 * Read ability: `og-templates/list-block-pattern-categories`.
 *
 * Wraps `GET /wp/v2/block-patterns/categories` via `rest_do_request()`. Returns
 * the registered block-pattern categories (the taxonomy that groups block
 * patterns, e.g. "Headers", "Footers", "Call to Action"), each with its name and
 * label. Pairs with `og-templates/list-patterns` so an agent can group patterns by
 * category. Read-only.
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
		return array(
			'label'               => __( 'List Block Pattern Categories', 'abilities-catalog' ),
			'description'         => __( 'Lists the registered block-pattern categories (the groupings used to organize block patterns). Pairs with the list-patterns ability to group patterns by category.', 'abilities-catalog' ),
			'category'            => 'og-core-templates',
			'input_schema'        => array(),
			'output_schema'       => array(
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
	 * Permission check: `edit_posts` (catalog capability for reading pattern categories).
	 *
	 * Deliberately hardens the read to the catalog `edit_posts` capability. Core's
	 * controller permits `edit_posts` OR the `edit_posts` cap of any `show_in_rest`
	 * post type; this coarser guard is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the pattern category registry.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection, or the REST error.
	 */
	public function execute( $input = null ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-patterns/categories' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
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
