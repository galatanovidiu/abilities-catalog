<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-terms/delete-term` (generic, keyed by `taxonomy`).
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
		return 'og-terms/delete-term';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Term', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a term in any REST-enabled taxonomy by ID. Taxonomy terms have no Trash, so this cannot be undone.', 'abilities-catalog' ),
			'category'            => 'og-core-terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy slug (required), e.g. "category" or a custom taxonomy. Find available slugs via og-terms/list-taxonomies.', 'abilities-catalog' ),
					),
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The term ID to permanently delete (required). Find it via og-terms/list-terms or og-terms/get-term.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'taxonomy', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the term was permanently deleted.', 'abilities-catalog' ),
					),
					'id'                => array(
						'type'        => 'integer',
						'description' => __( 'The deleted term ID.', 'abilities-catalog' ),
					),
					'previous_taxonomy' => array(
						'type'        => 'string',
						'description' => __( 'The taxonomy the deleted term belonged to, before deletion.', 'abilities-catalog' ),
					),
					'previous_name'     => array(
						'type'        => 'string',
						'description' => __( 'The deleted term name, from the term as it existed before deletion.', 'abilities-catalog' ),
					),
					'previous_slug'     => array(
						'type'        => 'string',
						'description' => __( 'The deleted term slug, from the term as it existed before deletion.', 'abilities-catalog' ),
					),
					'previous_parent'   => array(
						'type'        => 'integer',
						'description' => __( 'The deleted term parent ID (0 if top-level), before deletion. Present only for hierarchical taxonomies.', 'abilities-catalog' ),
					),
					'previous_link'     => array(
						'type'        => 'string',
						'description' => __( 'The deleted term archive URL as it existed before deletion.', 'abilities-catalog' ),
					),
					'previous_count'    => array(
						'type'        => 'integer',
						'description' => __( 'The number of objects assigned to the term before deletion.', 'abilities-catalog' ),
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
	 * Permission check: coarse, taxonomy-level `delete_terms`; the route enforces the object.
	 *
	 * Validates the taxonomy (needed to resolve its cap) and checks the taxonomy's
	 * object-independent `delete_terms` capability — for a term, `delete_term` maps to
	 * exactly that cap with no owner-vs-others split, so this is never stricter or weaker
	 * than core. The object decision (and a missing-id 404) is left to the wrapped
	 * `DELETE /wp/v2/<rest_base>/<id>` route, so its specific `rest_term_invalid` 404
	 * reaches the caller instead of the generic denial the Abilities API substitutes for
	 * a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage the taxonomy's terms.
	 */
	public function hasPermission( $input ): bool {
		$input    = is_array( $input ) ? $input : array();
		$taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$taxonomy_obj = get_taxonomy( $taxonomy );
		if ( ! $taxonomy_obj || empty( $taxonomy_obj->show_in_rest ) ) {
			return false;
		}

		return current_user_can( $taxonomy_obj->cap->delete_terms );
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

		$result = array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => $id,
		);

		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();
		if ( isset( $previous['taxonomy'] ) ) {
			$result['previous_taxonomy'] = (string) $previous['taxonomy'];
		}
		if ( isset( $previous['name'] ) ) {
			$result['previous_name'] = (string) $previous['name'];
		}
		if ( isset( $previous['slug'] ) ) {
			$result['previous_slug'] = (string) $previous['slug'];
		}
		if ( isset( $previous['parent'] ) ) {
			$result['previous_parent'] = (int) $previous['parent'];
		}
		if ( isset( $previous['link'] ) ) {
			$result['previous_link'] = (string) $previous['link'];
		}
		if ( isset( $previous['count'] ) ) {
			$result['previous_count'] = (int) $previous['count'];
		}

		return $result;
	}
}
