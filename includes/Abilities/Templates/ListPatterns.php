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
 * Read ability: `templates/list-patterns`.
 *
 * Wraps `GET /wp/v2/block-patterns/patterns` via `rest_do_request()`. Returns
 * the flat list of registered block patterns (the read-only pattern registry,
 * not user-created `wp_block` patterns). Read-only.
 *
 * @since 0.1.0
 */
final class ListPatterns implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/list-patterns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Patterns', 'abilities-catalog' ),
			'description'         => __( 'Lists the registered block patterns available on the site.', 'abilities-catalog' ),
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
							'additionalProperties' => true,
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
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the pattern registry.
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
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$items = rest_get_server()->response_to_data( $response, false );

		return array(
			'items' => is_array( $items ) ? $items : array(),
		);
	}
}
