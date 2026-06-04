<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `terms/delete-term` (generic, keyed by `taxonomy`).
 *
 * Resolves the taxonomy's REST base and wraps `DELETE /wp/v2/<rest_base>/<id>` with
 * `force=true` via `rest_do_request()`, permanently deleting the term (taxonomy
 * terms have no Trash) for any registered `show_in_rest` taxonomy. The `rest_base`
 * is resolved from the taxonomy object (`->rest_base ?: $taxonomy`). The
 * `permission_callback` validates the taxonomy, then mirrors the terms controller
 * `delete_item_permissions_check`: object-level `delete_term`. This ability never
 * calls `wp_delete_term()` directly; it surfaces the REST route's `WP_Error`
 * unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteTerm implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'terms/delete-term';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Term', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a term in any REST-enabled taxonomy by ID. Taxonomy terms have no Trash, so this cannot be undone.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy slug (required), e.g. "category" or a custom taxonomy.', 'abilities-catalog' ),
					),
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The term ID to permanently delete (required).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'taxonomy', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the term was permanently deleted.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted term ID.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit-tags.php?taxonomy={taxonomy}',
			),
		);
	}

	/**
	 * Permission check: validate the taxonomy is registered and `show_in_rest`, then
	 * apply object-level `delete_term` on the target term.
	 *
	 * Mirrors the REST terms controller `delete_item_permissions_check`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the term.
	 */
	public function hasPermission( $input ): bool {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( '' === $taxonomy || $id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj || empty( $taxonomy_obj->show_in_rest ) ) {
			return false;
		}

		return current_user_can( 'delete_term', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or an error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$id       = absint( $input['id'] );

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'rest_taxonomy_invalid', __( 'Invalid taxonomy.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj || empty( $taxonomy_obj->show_in_rest ) ) {
			return new WP_Error( 'rest_taxonomy_not_rest', __( 'Taxonomy is not available via the REST API.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$rest_base = ! empty( $taxonomy_obj->rest_base ) ? $taxonomy_obj->rest_base : $taxonomy;

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/' . $rest_base . '/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => $id,
		);
	}
}
