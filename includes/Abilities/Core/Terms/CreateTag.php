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
 * T1 safe-write ability: `terms/create-tag`.
 *
 * Wraps `POST /wp/v2/tags` via `rest_do_request()` and returns the new term's
 * id, name, slug, and public archive link. The `post_tag` taxonomy is non-hierarchical (no parent),
 * so the permission check mirrors the REST terms controller create path for a
 * non-hierarchical taxonomy: `current_user_can( get_taxonomy('post_tag')->cap->assign_terms )`
 * — NOT `edit_terms`. The REST route re-checks the capability and sanitizes
 * term fields underneath (defense in depth).
 *
 * @since 0.3.0
 */
final class CreateTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/create-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Tag', 'abilities-catalog' ),
			'description'         => __( 'Creates a new tag term.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'The tag name (required).', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'The tag slug. Generated from the name when omitted.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The tag description.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'name', 'slug' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The new tag term ID.', 'abilities-catalog' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The tag name.', 'abilities-catalog' ),
					),
					'slug' => array(
						'type'        => 'string',
						'description' => __( 'The tag slug.', 'abilities-catalog' ),
					),
					'link' => array(
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
				'screen'       => 'edit-tags.php?taxonomy=post_tag',
			),
		);
	}

	/**
	 * Permission check mirroring the REST terms controller create path.
	 *
	 * `post_tag` is non-hierarchical, so creation requires the taxonomy's
	 * `assign_terms` capability — not `edit_terms`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create a tag.
	 */
	public function hasPermission( $input ): bool {
		$taxonomy = get_taxonomy( 'post_tag' );
		if ( ! $taxonomy ) {
			return false;
		}

		return current_user_can( $taxonomy->cap->assign_terms );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new term's id, name, slug, link, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/tags' );

		if ( isset( $input['name'] ) ) {
			$request->set_param( 'name', sanitize_text_field( (string) $input['name'] ) );
		}

		if ( isset( $input['slug'] ) && '' !== $input['slug'] ) {
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
			'id'   => (int) ( $data['id'] ?? 0 ),
			'name' => (string) ( $data['name'] ?? '' ),
			'slug' => (string) ( $data['slug'] ?? '' ),
			'link' => (string) ( $data['link'] ?? '' ),
		);
	}
}
