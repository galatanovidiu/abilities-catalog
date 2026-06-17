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
 * T1 safe-write ability: `terms/update-category`.
 *
 * Wraps `POST /wp/v2/categories/<id>` via `rest_do_request()` and returns the
 * updated term's id, name, slug, description, and parent. The permission check mirrors the REST
 * terms controller update path: object-level `current_user_can('edit_term', $id)`.
 * The REST route re-checks the capability and sanitizes term fields underneath
 * (defense in depth).
 *
 * @since 0.3.0
 */
final class UpdateCategory implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/update-category';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Category', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing category term by ID.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The category term ID (required). Discover IDs via terms/list-categories.', 'abilities-catalog' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The category name.', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The category slug.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The category description.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent category term ID. Pass 0 to clear the parent and make the category top-level.', 'abilities-catalog' ),
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
						'description' => __( 'The category term ID.', 'abilities-catalog' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The category name.', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The category slug.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The category description.', 'abilities-catalog' ),
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent category term ID (0 when top-level).', 'abilities-catalog' ),
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
				'screen'       => 'term.php?taxonomy=category&tag_ID={id}',
			),
		);
	}

	/**
	 * Permission check: coarse `edit_categories`; the route enforces the object.
	 *
	 * For `category`, `edit_term` maps to `edit_categories` with no owner-vs-others
	 * split, so this coarse, object-independent check is exactly what core requires —
	 * never stricter, never weaker. The object decision (and a missing-id 404) is left to
	 * the wrapped `POST /wp/v2/categories/<id>` route, so its specific `rest_term_invalid`
	 * 404 reaches the caller instead of the generic denial the Abilities API substitutes
	 * for a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage categories.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_categories' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated term's id, name, slug, description, parent, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/categories/' . $id );

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

		if ( isset( $input['parent'] ) ) {
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
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => (int) ( $data['parent'] ?? 0 ),
		);
	}
}
