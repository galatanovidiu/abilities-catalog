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
 * Read ability: `og-templates/list-block-types`.
 *
 * Wraps `GET /wp/v2/block-types` via the Abilities REST Adapter. The input schema
 * is DERIVED from the route (no input). The output is OVERRIDDEN to the catalog's
 * flat field set through {@see shapeOutput()}: a lightweight overview of the block
 * types registered on the site (`core/paragraph`, `core/heading`, and any plugin-
 * or theme-registered blocks), each flattened to its name, title, category, and
 * dynamic flag. Use this to discover which blocks exist. It does not return block
 * attributes, supports, or nesting rules, so it is not sufficient on its own to
 * compose attribute-correct block markup. Permission delegates to the route's own
 * check (no `require_permission` floor is set). Read-only.
 *
 * @since 0.5.0
 */
final class ListBlockTypes implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-block-types';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/block-types',
				'method'          => 'GET',
				'label'           => __( 'List Block Types', 'abilities-catalog' ),
				'description'     => __( 'Lists the block types registered on the site (core, plugin, and theme blocks) as a lightweight registry overview: name, title, category, and dynamic flag. Use this to discover which blocks exist. It does not return block attributes, supports, or nesting rules, so it is not sufficient on its own to compose attribute-correct block markup.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'name', 'title', 'category', 'is_dynamic' ),
								'properties'           => array(
									'name'       => array(
										'type'        => 'string',
										'description' => __( 'The block type name (e.g. "core/paragraph"). Use this in block markup.', 'abilities-catalog' ),
									),
									'title'      => array(
										'type'        => 'string',
										'description' => __( 'The human-readable block title.', 'abilities-catalog' ),
									),
									'category'   => array(
										'type'        => 'string',
										'description' => __( 'The block category (e.g. "text", "media", "design").', 'abilities-catalog' ),
									),
									'is_dynamic' => array(
										'type'        => 'boolean',
										'description' => __( 'Whether the block renders dynamically on the server.', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of registered block types.', 'abilities-catalog' ),
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
	 * Flattens the REST block-types collection to the catalog's `items` shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. The
	 * adapter hands a `{ items, total, total_pages }` envelope for a collection
	 * route; this reads `$data['items']` and maps each block-type row to the
	 * four-field set, returning `{ items: [...] }`. `$input` and `$response` are
	 * part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat `items` collection.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$rows  = is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'name'       => (string) ( $row['name'] ?? '' ),
				'title'      => (string) ( $row['title'] ?? '' ),
				'category'   => (string) ( $row['category'] ?? '' ),
				'is_dynamic' => (bool) ( $row['is_dynamic'] ?? false ),
			);
		}

		return array(
			'items' => $items,
		);
	}
}
