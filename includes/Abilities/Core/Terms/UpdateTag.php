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
 * T1 safe-write ability: `og-terms/update-tag`.
 *
 * Wraps `POST /wp/v2/tags/<id>` via `rest_do_request()` and returns the updated
 * term's id, name, slug, description, and public archive link. The permission check mirrors the REST terms
 * controller update path: object-level `current_user_can('edit_term', $id)`.
 * The REST route re-checks the capability and sanitizes term fields underneath
 * (defense in depth).
 *
 * @since 0.3.0
 */
final class UpdateTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/update-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Tag', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing tag term by ID.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __( 'The tag term ID (required).', 'abilities-catalog' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The tag name.', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The tag slug.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The tag description.', 'abilities-catalog' ),
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
						'description' => __( 'The tag term ID.', 'abilities-catalog' ),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The tag name.', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The tag slug.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The tag description.', 'abilities-catalog' ),
					),
					'link'        => array(
						'type'        => 'string',
						'description' => __( 'The public tag archive URL.', 'abilities-catalog' ),
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
				'screen'       => 'term.php?taxonomy=post_tag&tag_ID={id}',
			),
		);
	}

	/**
	 * Permission check: coarse `edit_post_tags`; the route enforces the object.
	 *
	 * For `post_tag`, `edit_term` maps to `edit_post_tags` with no owner-vs-others split,
	 * so this coarse, object-independent check is exactly what core requires — never
	 * stricter, never weaker. The object decision (and a missing-id 404) is left to the
	 * wrapped `POST /wp/v2/tags/<id>` route, so its specific `rest_term_invalid` 404
	 * reaches the caller instead of the generic denial the Abilities API substitutes for
	 * a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage tags.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_post_tags' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated term's id, name, slug, description, link, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/tags/' . $id );

		// Forward a field whenever the caller supplied the key, including an
		// explicit empty string. On an update, key presence is the caller's
		// intent: an omitted field means "leave unchanged", while an explicit ''
		// means "blank this field". The REST terms controller gates name/slug on
		// `isset()` only (prepare_item_for_database) and forwards the empty value,
		// so an empty `name` reaches wp_update_term's `'' === trim($name)` check
		// and surfaces its `empty_term_name` error, and an empty `slug` reaches
		// core, which regenerates the slug from the effective `name`. A `'' !==`
		// guard here would drop
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
			'link'        => (string) ( $data['link'] ?? '' ),
		);
	}
}
