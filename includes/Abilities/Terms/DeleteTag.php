<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Terms;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `terms/delete-tag`.
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
		return 'terms/delete-tag';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Tag', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a tag term by ID. Taxonomy terms have no Trash, so this cannot be undone.', 'abilities-catalog' ),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The tag term ID to permanently delete.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the tag term was permanently deleted.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted tag term ID.', 'abilities-catalog' ),
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
	 * Permission check: object-level `delete_term` on the target term.
	 *
	 * Mirrors the REST terms controller `delete_item_permissions_check`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the tag term.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'delete_term', $id );
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
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => $id,
		);
	}
}
