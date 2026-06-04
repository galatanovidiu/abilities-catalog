<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `content/delete-cpt-item` (generic, keyed by `post_type`).
 *
 * Resolves the type's REST base and wraps `DELETE /wp/v2/<rest_base>/<id>` with
 * `force=true` via `rest_do_request()`, permanently deleting the item (bypassing
 * the Trash) for any registered `show_in_rest` post type. The `permission_callback`
 * validates the type, then mirrors the posts controller
 * `delete_item_permissions_check`: object-level `delete_post`. This ability never
 * calls `wp_delete_post()` directly; it surfaces the REST route's `WP_Error`
 * unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteCptItem implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/delete-cpt-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Custom Post Type Item', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes an item of any REST-enabled post type by ID, bypassing the Trash. This cannot be undone.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'The post type slug (required).', 'abilities-catalog' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The item ID to permanently delete (required).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_type', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the item was permanently deleted.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted item ID.', 'abilities-catalog' ),
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
				'screen'       => 'edit.php',
			),
		);
	}

	/**
	 * Permission check: validate the type is registered and `show_in_rest`, then
	 * apply object-level `delete_post` on the target item.
	 *
	 * Mirrors the REST posts controller `delete_item_permissions_check`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the item.
	 */
	public function hasPermission( $input ): bool {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';
		$id        = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( '' === $post_type || $id <= 0 ) {
			return false;
		}

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return false;
		}

		return current_user_can( 'delete_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete, not Trash).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or an error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';
		$id        = absint( $input['id'] );

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'The requested post type does not exist or is not available in REST.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$rest_base = $obj->rest_base ?: $post_type;

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
