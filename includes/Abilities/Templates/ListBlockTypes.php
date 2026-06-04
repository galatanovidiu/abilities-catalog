<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `templates/list-block-types`.
 *
 * Wraps `GET /wp/v2/block-types` via `rest_do_request()` and shapes the result.
 * Returns the registered block types available on the site (`core/paragraph`,
 * `core/heading`, and any plugin- or theme-registered blocks), each flattened to
 * its name, title, category, and dynamic flag. Use this to discover which blocks
 * an agent may compose into Gutenberg block markup before creating or updating
 * content. Read-only.
 *
 * @since 0.5.0
 */
final class ListBlockTypes implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/list-block-types';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Block Types', 'abilities-catalog' ),
			'description'         => __( 'Lists the block types registered on the site (core, plugin, and theme blocks). Use this to discover which blocks exist before composing Gutenberg block markup for new or updated content.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'name' ),
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
	 * Permission check: `edit_posts` (catalog capability for reading block types).
	 *
	 * Mirrors the block-types controller, whose first gate is `edit_posts`; this
	 * guard is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the block type registry.
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
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-types' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
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
