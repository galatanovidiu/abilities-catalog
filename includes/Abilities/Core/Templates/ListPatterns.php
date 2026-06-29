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
 * Read ability: `og-templates/list-patterns`.
 *
 * Wraps `GET /wp/v2/block-patterns/patterns` via the Abilities REST Adapter. The
 * input schema is DERIVED from the route (a no-input collection read). The output
 * is OVERRIDDEN to the catalog's closed field set through {@see shapeOutput()},
 * which projects the adapter's `{ items, total, total_pages }` collection envelope
 * down to `{ items }` of flattened pattern rows. Returns the read-only registered
 * pattern registry, not user-created `wp_block` synced patterns; for those use
 * `og-templates/list-synced-patterns`. Permission delegates to the route's own
 * check (no `require_permission` floor), so visibility follows REST. Read-only.
 *
 * @since 0.1.0
 */
final class ListPatterns implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-patterns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/block-patterns/patterns',
				'method'          => 'GET',
				'label'           => __( 'List Patterns', 'abilities-catalog' ),
				'description'     => __( 'Lists the registered block patterns available on the site (the read-only registered pattern registry). For user-created synced patterns use the list-synced-patterns ability.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'name', 'title' ),
								'properties'           => array(
									'name'           => array(
										'type'        => 'string',
										'description' => __( 'The pattern name (e.g. "core/query-standard-posts").', 'abilities-catalog' ),
									),
									'title'          => array(
										'type'        => 'string',
										'description' => __( 'The human-readable pattern title.', 'abilities-catalog' ),
									),
									'description'    => array(
										'type'        => 'string',
										'description' => __( 'The pattern description.', 'abilities-catalog' ),
									),
									'content'        => array(
										'type'        => 'string',
										'description' => __( 'The resolved block markup for the pattern.', 'abilities-catalog' ),
									),
									'viewport_width' => array(
										'type'        => 'number',
										'description' => __( 'The pattern viewport width for inserter preview.', 'abilities-catalog' ),
									),
									'inserter'       => array(
										'type'        => 'boolean',
										'description' => __( 'Whether the pattern is visible in the inserter.', 'abilities-catalog' ),
									),
									'categories'     => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'The pattern category slugs.', 'abilities-catalog' ),
									),
									'keywords'       => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'The pattern keywords.', 'abilities-catalog' ),
									),
									'block_types'    => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'Block types the pattern is intended to be used with.', 'abilities-catalog' ),
									),
									'post_types'     => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'Post types the pattern is restricted to.', 'abilities-catalog' ),
									),
									'template_types' => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'Template types where the pattern fits.', 'abilities-catalog' ),
									),
									'source'         => array(
										'type'        => 'string',
										'description' => __( 'Where the pattern comes from (e.g. "core", "plugin", "theme").', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of registered block patterns.', 'abilities-catalog' ),
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
	 * Projects the adapter's collection envelope to the catalog's closed row set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for a `get_items`
	 * collection route. Each row is reduced to the two guaranteed keys plus the
	 * optional keys that REST actually returned, so the shaped result never leaks the
	 * runtime-appended extra fields core attaches to a raw pattern row. `$input` and
	 * `$response` are part of the callback signature but unused here — the envelope
	 * carries everything this shape needs.
	 *
	 * @param mixed               $data     The collection envelope (associative array with `items`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped `{ items }` collection.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$rows  = is_array( $data ) && isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array(
				'name'  => (string) ( $row['name'] ?? '' ),
				'title' => (string) ( $row['title'] ?? '' ),
			);

			if ( isset( $row['description'] ) && '' !== $row['description'] ) {
				$item['description'] = (string) $row['description'];
			}
			if ( isset( $row['content'] ) ) {
				$item['content'] = (string) $row['content'];
			}
			if ( isset( $row['viewport_width'] ) ) {
				$item['viewport_width'] = (float) $row['viewport_width'];
			}
			if ( isset( $row['inserter'] ) ) {
				$item['inserter'] = (bool) $row['inserter'];
			}
			if ( isset( $row['categories'] ) && is_array( $row['categories'] ) ) {
				$item['categories'] = array_values( array_map( 'strval', $row['categories'] ) );
			}
			if ( isset( $row['keywords'] ) && is_array( $row['keywords'] ) ) {
				$item['keywords'] = array_values( array_map( 'strval', $row['keywords'] ) );
			}
			if ( isset( $row['block_types'] ) && is_array( $row['block_types'] ) ) {
				$item['block_types'] = array_values( array_map( 'strval', $row['block_types'] ) );
			}
			if ( isset( $row['post_types'] ) && is_array( $row['post_types'] ) ) {
				$item['post_types'] = array_values( array_map( 'strval', $row['post_types'] ) );
			}
			if ( isset( $row['template_types'] ) && is_array( $row['template_types'] ) ) {
				$item['template_types'] = array_values( array_map( 'strval', $row['template_types'] ) );
			}
			if ( isset( $row['source'] ) && '' !== $row['source'] ) {
				$item['source'] = (string) $row['source'];
			}

			$items[] = $item;
		}

		return array(
			'items' => $items,
		);
	}
}
