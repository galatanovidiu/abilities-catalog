<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_User_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-privacy/cancel-request`.
 *
 * Permanently deletes a personal-data request record by wrapping the core
 * function `wp_delete_post($request_id, true)`. The deleted thing is the
 * `user_request` custom-post-type post that records the export or erasure
 * request — NOT any exported or erased personal data. Deleting the record is
 * how the wp-admin Export / Erase Personal Data screens "remove" a request
 * row: the requests list-table bulk `delete` action calls
 * `wp_delete_post($request_id, true)` in
 * `WP_Privacy_Requests_Table::process_bulk_action()`
 * (wp-admin/includes/class-wp-privacy-requests-table.php:314-316).
 *
 * The deletion is permanent (force delete bypasses Trash), so this ability is
 * annotated destructive. It is a net-new wrapper: there is no REST route for
 * user requests, so the core function is called directly. The core function
 * performs no capability check, so the `permission_callback` is the hard
 * authorization guard.
 *
 * The `permission_callback` floors at the object-independent capability
 * `manage_privacy_options`. The per-type screen cap is enforced in `execute()`
 * after the existence check, mirroring the B2 coarse-guard pattern
 * ({@see \GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Terms\AttachPostTerms},
 * {@see \GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users\DeleteUser}), so the
 * specific 404 (missing/non-`user_request` ID) and per-type 403 reach the caller
 * instead of the generic `ability_invalid_permissions` the Abilities API
 * substitutes when `permission_callback` returns a non-`true` value. The per-type
 * caps are:
 *   - `export_personal_data` → `export_others_personal_data`
 *     (wp-admin/export-personal-data.php:12)
 *   - `remove_personal_data` → `erase_others_personal_data` AND `delete_users`
 *     (wp-admin/erase-personal-data.php:12)
 *
 * @since 0.4.0
 */
final class CancelRequest implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-privacy/cancel-request';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Cancel Request', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a personal-data request record (the export or erasure request row). Does not delete exported or erased personal data.', 'abilities-catalog' ),
			'category'            => 'privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the user_request post to delete. Find request IDs with og-privacy/list-export-requests or og-privacy/list-erase-requests.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'request_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'request_id', 'cancelled' ),
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'description' => __( 'The deleted request ID.', 'abilities-catalog' ),
					),
					'cancelled'  => array(
						'type'        => 'boolean',
						'description' => __( 'True when the request record was deleted.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the object-independent `manage_privacy_options` floor.
	 *
	 * Every successful caller holds `manage_privacy_options` (core maps the
	 * per-type `*_others_personal_data` caps to the same `manage_options` /
	 * `manage_network` primitive), so this coarse floor is never weaker than core.
	 * The per-type cap (and the missing-request 404) is enforced in `execute()`,
	 * keeping this guard object-independent so the specific error surfaces instead
	 * of the generic denial. The wrapped core function performs no capability check.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage privacy requests.
	 */
	public function hasPermission( $input ): bool {
		$input      = is_array( $input ) ? $input : array();
		$request_id = isset( $input['request_id'] ) ? absint( $input['request_id'] ) : 0;
		if ( $request_id < 1 ) {
			return false;
		}

		return current_user_can( 'manage_privacy_options' );
	}

	/**
	 * Executes the ability by permanently deleting the request record.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{request_id:int,cancelled:bool}|\WP_Error
	 */
	public function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$request_id = isset( $input['request_id'] ) ? absint( $input['request_id'] ) : 0;

		if ( $request_id < 1 ) {
			return new WP_Error(
				'invalid_request',
				__( 'A valid request ID is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		// Confirm the post exists and is a user_request record before deleting.
		$request = wp_get_user_request( $request_id );
		if ( ! $request instanceof WP_User_Request ) {
			return new WP_Error(
				'invalid_request',
				__( 'No personal-data request found for that ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Per-type authorization, relocated from permission_callback so the 404
		// above is reachable on the routed path. Mirror the wp-admin screen caps;
		// an unknown action type is denied (matching the old default:false gate).
		switch ( $request->action_name ) {
			case 'export_personal_data':
				$allowed = current_user_can( 'export_others_personal_data' );
				break;
			case 'remove_personal_data':
				$allowed = current_user_can( 'erase_others_personal_data' ) && current_user_can( 'delete_users' );
				break;
			default:
				$allowed = false;
		}

		if ( ! $allowed ) {
			return new WP_Error(
				'rest_cannot_delete',
				__( 'Sorry, you are not allowed to cancel this request.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// wp_delete_post() returns the WP_Post on success, but a non-null
		// `pre_delete_post` filter short-circuits and returns that value
		// unchanged — including a WP_Error (wp-includes/post.php:3808-3811).
		$deleted = wp_delete_post( $request_id, true );
		if ( $deleted instanceof WP_Error ) {
			return $deleted;
		}
		if ( ! $deleted instanceof \WP_Post ) {
			return new WP_Error(
				'cancel_failed',
				__( 'The request record could not be deleted.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'request_id' => $request_id,
			'cancelled'  => true,
		);
	}
}
