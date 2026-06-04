<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `menus/delete-navigation`.
 *
 * Wraps `DELETE /wp/v2/navigation/<id>` via `rest_do_request()`, deleting a block
 * navigation menu (a `wp_navigation` post). Unlike classic menus, `wp_navigation`
 * supports Trash: by default this trashes the navigation (recoverable); set
 * `force` to true to delete it permanently. A navigation menu can be reused across
 * the site, so deleting it affects every Navigation block that references it.
 *
 * The `permission_callback` mirrors the posts controller
 * `delete_item_permissions_check`: object-level `delete_post` on the navigation id,
 * which `map_meta_cap` resolves to `edit_theme_options` for `wp_navigation`. This
 * ability never calls `wp_delete_post()` directly; it surfaces the REST route's
 * `WP_Error` unchanged. Destructive: exposed to the browser only when both the
 * write and destructive adapter settings are on. Capability is the hard guard.
 *
 * @since 0.5.0
 */
final class DeleteNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/delete-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Navigation', 'abilities-catalog' ),
			'description'         => __( 'Deletes a block navigation menu (a wp_navigation post) by ID. By default it is moved to Trash and can be restored; set force to true to delete it permanently. A navigation menu may be used by Navigation blocks across the site, so deleting it affects every place it appears.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => __( 'The navigation menu (wp_navigation post) ID to delete.', 'abilities-catalog' ),
					),
					'force' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'If true, delete permanently (bypass Trash). If false (default), move to Trash.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'trashed', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the navigation was permanently deleted.', 'abilities-catalog' ),
					),
					'trashed' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the navigation was moved to Trash (recoverable).', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted navigation menu ID.', 'abilities-catalog' ),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check: object-level `delete_post` on the target navigation.
	 *
	 * Mirrors the navigation (posts) REST controller `delete_item_permissions_check`;
	 * `map_meta_cap` resolves `delete_post` to `edit_theme_options` for `wp_navigation`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the navigation menu.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'delete_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Passes `force` through (default false = Trash). Any REST error is returned to
	 * the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted/trashed flags and id, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$force   = ! empty( $input['force'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/navigation/' . $id );
		$request->set_param( 'force', $force );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		// With force=true the route returns {deleted:true, previous:{…}}.
		// With force=false it returns the trashed post object (status "trash").
		$deleted = (bool) ( $data['deleted'] ?? false );
		$status  = $data['status'] ?? ( $data['previous']['status'] ?? '' );
		$trashed = ! $deleted && 'trash' === $status;

		return array(
			'deleted' => $deleted,
			'trashed' => $trashed,
			'id'      => $id,
		);
	}
}
