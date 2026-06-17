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
 * T1 safe-write ability: `terms/update-term` (generic, keyed by `taxonomy`).
 *
 * Wraps `POST /wp/v2/<rest_base>/<id>` via `rest_do_request()` for any
 * `show_in_rest` taxonomy and returns the updated term's id, name, slug,
 * taxonomy, description, and parent. The `rest_base` is resolved from the
 * taxonomy object
 * (`->rest_base ?: $taxonomy`).
 *
 * The permission check mirrors the REST terms controller update path:
 * object-level `current_user_can('edit_term', $id)`. The REST route re-checks
 * the capability and sanitizes term fields underneath (defense in depth).
 *
 * @since 0.3.0
 */
final class UpdateTerm implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/update-term';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Term', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing term in any REST-enabled taxonomy by ID.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy'    => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy slug (required), e.g. "category" or a custom taxonomy.', 'abilities-catalog' ),
					),
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The term ID (required).', 'abilities-catalog' ),
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
						'description' => __( 'The parent term ID (only meaningful for hierarchical taxonomies).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'taxonomy', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name', 'slug', 'taxonomy' ),
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
					'taxonomy'    => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy the term belongs to.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The term description.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent term ID (0 when top-level or non-hierarchical).', 'abilities-catalog' ),
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
				'screen'       => 'term.php?taxonomy={taxonomy}&tag_ID={id}',
			),
		);
	}

	/**
	 * Permission check mirroring the REST terms controller update path.
	 *
	 * Validates the taxonomy is registered and `show_in_rest`, then applies the
	 * object-level `edit_term` capability on the target term.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the term.
	 */
	public function hasPermission( $input ): bool {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || $id <= 0 ) {
			return false;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj || empty( $taxonomy_obj->show_in_rest ) ) {
			return false;
		}

		return current_user_can( 'edit_term', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated term's id, name, slug, taxonomy, description, parent, or the REST error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'rest_taxonomy_invalid', __( 'Invalid taxonomy.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj || empty( $taxonomy_obj->show_in_rest ) ) {
			return new \WP_Error( 'rest_taxonomy_not_rest', __( 'Taxonomy is not available via the REST API.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$rest_base = ! empty( $taxonomy_obj->rest_base ) ? $taxonomy_obj->rest_base : $taxonomy;
		$request   = new WP_REST_Request( 'POST', '/wp/v2/' . $rest_base . '/' . $id );

		// Forward a field whenever the caller supplied the key, including an
		// explicit empty string. On an update, key presence is the caller's
		// intent: an omitted field means "leave unchanged", while an explicit ''
		// means "blank this field". The REST terms controller gates name/slug on
		// `isset()` only (prepare_item_for_database) and forwards the empty value,
		// so an empty `name` reaches wp_update_term's `'' === trim($name)` check
		// and surfaces its `empty_term_name` error, and an empty `slug` reaches
		// core, which keeps the existing slug. A `'' !==` guard here would drop
		// the value and silently no-op, discarding intent and hiding core's error.
		if ( array_key_exists( 'name', $input ) ) {
			$request->set_param( 'name', sanitize_text_field( (string) $input['name'] ) );
		}

		if ( array_key_exists( 'slug', $input ) ) {
			$request->set_param( 'slug', sanitize_title( (string) $input['slug'] ) );
		}

		if ( isset( $input['description'] ) ) {
			$request->set_param( 'description', sanitize_text_field( (string) $input['description'] ) );
		}

		if ( isset( $input['parent'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$request->set_param( 'parent', absint( $input['parent'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'          => (int) ( $data['id'] ?? $id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'taxonomy'    => (string) ( $data['taxonomy'] ?? $taxonomy ),
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => (int) ( $data['parent'] ?? 0 ),
		);
	}
}
