<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-terms/get-tag`.
 *
 * Wraps `GET /wp/v2/tags/<id>` via `rest_do_request()` and shapes the response
 * into a flat field set.
 *
 * @since 0.1.0
 */
final class GetTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/get-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Tag', 'abilities-catalog' ),
			'description'         => __( 'Returns a single post-tag term by ID. Tag-specific read; discover IDs with og-terms/list-tags. Use og-terms/get-category for categories or og-terms/get-term for an arbitrary taxonomy.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The tag term ID. Discover IDs with og-terms/list-tags.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name', 'slug' ),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The term ID.', 'abilities-catalog' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The term name.', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The term slug.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The term description.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent term ID.', 'abilities-catalog' ),
					),
					'count'       => array(
						'type'        => 'integer',
						'description' => __( 'Number of published posts for the term.', 'abilities-catalog' ),
					),
					'taxonomy'    => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy the term belongs to.', 'abilities-catalog' ),
					),
					'link'        => array(
						'type'        => 'string',
						'description' => __( 'The public term archive URL.', 'abilities-catalog' ),
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
	 * Permission check: term reads require an authenticated user.
	 *
	 * Edit-context additionally requires `manage_post_tags`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the tag.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		$context = $input['context'] ?? 'view';
		if ( 'edit' === $context ) {
			return current_user_can( 'manage_post_tags' );
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat term fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/tags/' . $id );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'          => (int) ( $data['id'] ?? $id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => (int) ( $data['parent'] ?? 0 ),
			'count'       => (int) ( $data['count'] ?? 0 ),
			'taxonomy'    => (string) ( $data['taxonomy'] ?? 'post_tag' ),
			'link'        => (string) ( $data['link'] ?? '' ),
		);
	}
}
