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
 * T2 destructive write ability: `og-terms/delete-tag`.
 *
 * Wraps `DELETE /wp/v2/tags/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the tag term (taxonomy terms have no Trash). The
 * `permission_callback` mirrors the terms controller
 * `delete_item_permissions_check`: object-level `delete_term`. This ability never
 * calls `wp_delete_term()` directly; it surfaces the REST route's `WP_Error`
 * unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteTag implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-terms/delete-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Tag', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a tag term by ID. Taxonomy terms have no Trash, so this cannot be undone. Deleting the tag also removes it from every object it was assigned to.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The tag term ID to permanently delete. Find it via og-terms/list-tags or og-terms/get-tag.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'        => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the tag term was permanently deleted.', 'abilities-catalog' ),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => __( 'The deleted tag term ID.', 'abilities-catalog' ),
					),
					'previous_name'  => array(
						'type'        => 'string',
						'description' => __( 'The deleted tag name, from the term as it existed before deletion.', 'abilities-catalog' ),
					),
					'previous_slug'  => array(
						'type'        => 'string',
						'description' => __( 'The deleted tag slug, from the term as it existed before deletion.', 'abilities-catalog' ),
					),
					'previous_link'  => array(
						'type'        => 'string',
						'description' => __( 'The deleted tag archive URL as it existed before deletion.', 'abilities-catalog' ),
					),
					'previous_count' => array(
						'type'        => 'integer',
						'description' => __( 'The number of objects assigned to the tag before deletion.', 'abilities-catalog' ),
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
				'screen'       => 'edit-tags.php?taxonomy=post_tag',
			),
		);
	}

	/**
	 * Permission check: coarse `delete_post_tags`; the route enforces the object.
	 *
	 * For `post_tag`, `delete_term` maps to `delete_post_tags` with no owner-vs-others
	 * split, so this coarse, object-independent check is exactly what core requires —
	 * never stricter, never weaker. The object decision (and a missing-id 404) is left to
	 * the wrapped `DELETE /wp/v2/tags/<id>` route, so its specific `rest_term_invalid`
	 * 404 reaches the caller instead of the generic denial the Abilities API substitutes
	 * for a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage tags.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'delete_post_tags' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/tags/' . $id );
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
		if ( isset( $previous['name'] ) ) {
			$result['previous_name'] = (string) $previous['name'];
		}
		if ( isset( $previous['slug'] ) ) {
			$result['previous_slug'] = (string) $previous['slug'];
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
