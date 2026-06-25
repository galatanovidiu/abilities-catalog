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
 * T1 safe-write ability: `og-terms/create-category`.
 *
 * Wraps `POST /wp/v2/categories` via `rest_do_request()` and returns the new
 * term's id, name, slug, parent, and public archive link. The `category`
 * taxonomy is hierarchical, so the
 * permission check mirrors the REST terms controller create path for a
 * hierarchical taxonomy: `current_user_can( get_taxonomy('category')->cap->edit_terms )`
 * (which resolves to `manage_categories`). The REST route re-checks the
 * capability and sanitizes term fields underneath (defense in depth).
 *
 * @since 0.3.0
 */
final class CreateCategory implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/create-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Category', 'abilities-catalog' ),
			'description'         => __( 'Creates a new category term.', 'abilities-catalog' ),
			'category'            => 'og-core-terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The category name (required).', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The category slug. Generated from the name when omitted.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The category description.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent category term ID.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name', 'slug' ),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __( 'The new category term ID.', 'abilities-catalog' ),
					),
					'name'   => array(
						'type'        => 'string',
						'description' => __( 'The category name.', 'abilities-catalog' ),
					),
					'slug'   => array(
						'type'        => 'string',
						'description' => __( 'The category slug.', 'abilities-catalog' ),
					),
					'parent' => array(
						'type'        => 'integer',
						'description' => __( 'The parent category term ID (0 when top-level).', 'abilities-catalog' ),
					),
					'link'   => array(
						'type'        => 'string',
						'description' => __( 'The public category archive URL.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit-tags.php?taxonomy=category',
			),
		);
	}

	/**
	 * Permission check mirroring the REST terms controller create path.
	 *
	 * `category` is hierarchical, so creation requires the taxonomy's
	 * `edit_terms` capability (`manage_categories`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create a category.
	 */
	public function hasPermission( $input ): bool {
		$taxonomy = get_taxonomy( 'category' );
		if ( ! $taxonomy ) {
			return false;
		}

		return current_user_can( $taxonomy->cap->edit_terms );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new term's id, name, slug, parent, link, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/categories' );

		if ( isset( $input['name'] ) ) {
			$request->set_param( 'name', sanitize_text_field( (string) $input['name'] ) );
		}

		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
			$request->set_param( 'slug', sanitize_title( (string) $input['slug'] ) );
		}

		if ( isset( $input['description'] ) ) {
			$request->set_param( 'description', sanitize_text_field( (string) $input['description'] ) );
		}

		if ( ! empty( $input['parent'] ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'     => (int) ( $data['id'] ?? 0 ),
			'name'   => (string) ( $data['name'] ?? '' ),
			'slug'   => (string) ( $data['slug'] ?? '' ),
			'parent' => (int) ( $data['parent'] ?? 0 ),
			'link'   => (string) ( $data['link'] ?? '' ),
		);
	}
}
