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
 * Read ability: `og-templates/list-patterns`.
 *
 * Wraps `GET /wp/v2/block-patterns/patterns` via `rest_do_request()` and shapes
 * the result. Returns the flat list of registered block patterns (the read-only
 * pattern registry, not user-created `wp_block` synced patterns; for those use
 * `og-templates/list-synced-patterns`). Each row is projected into a closed set of
 * fields. Read-only.
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
		return array(
			'label'               => __( 'List Patterns', 'abilities-catalog' ),
			'description'         => __( 'Lists the registered block patterns available on the site (the read-only registered pattern registry). For user-created synced patterns use the list-synced-patterns ability.', 'abilities-catalog' ),
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
	 * Permission check: `edit_posts` (catalog capability for listing patterns).
	 *
	 * Deliberately hardens the read to the catalog `edit_posts` capability. Core's
	 * controller permits `edit_posts` OR the `edit_posts` cap of any `show_in_rest`
	 * post type; this coarser guard is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the pattern registry.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request and shaping the result.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped collection, or the REST error.
	 */
	public function execute( $input = null ) {
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
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
