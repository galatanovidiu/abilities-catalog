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
 * T2 non-destructive write ability: `og-privacy/confirm-request`.
 *
 * Administratively confirms a pending personal-data request by wrapping the
 * core function `_wp_privacy_account_request_confirmed($request_id)`, which
 * sets the request status to `request-confirmed`. The core function acts only
 * on a request currently in `request-pending` or `request-failed` state and is
 * a no-op otherwise (wp-includes/user.php:4216).
 *
 * Confirming is normally done by the data subject, not an admin: the public
 * `confirmaction` flow in wp-login.php fires
 * `do_action( 'user_request_action_confirmed', $request_id )` (wp-login.php:1276),
 * gated by a per-request key (`wp_validate_user_request_key`), NOT by a
 * capability (wp-login.php:1246). `_wp_privacy_account_request_confirmed()` is
 * one of two handlers wired to that action
 * (wp-includes/default-filters.php:442-443). This ability calls that handler
 * directly. There is no core admin handler that confirms a request behind a
 * capability gate, so this ability is an administrative override of the
 * email-key proof, and there is no core admin cap to mirror exactly.
 *
 * The cap is therefore floored at the strictest correct guard. The
 * `permission_callback` checks the object-independent `manage_privacy_options`;
 * the per-type screen cap is enforced in `execute()` after the existence check
 * (mirroring the B2 coarse-guard pattern), so the specific 404 (missing request)
 * and per-type 403 reach the caller instead of the generic denial. The per-type
 * caps are the same that gate the wp-admin screen for that request type:
 *   - `export_personal_data` → `export_others_personal_data`
 *     (wp-admin/export-personal-data.php:12)
 *   - `remove_personal_data` → `erase_others_personal_data` AND `delete_users`
 *     (wp-admin/erase-personal-data.php:12)
 * The per-type authorization runs before the confirmable-state (409) check so an
 * unauthorized-for-type caller is not told whether the request is confirmable.
 *
 * CAP UNCERTAINTY: no core admin handler gates this exact operation, so the
 * `manage_privacy_options` floor is an added guard, not a core mirror. It is
 * strict (never weaker than the per-type screen cap), but it could be stricter
 * than strictly necessary. Flagged for review.
 *
 * @since 0.3.0
 */
final class ConfirmRequest implements Ability {

	/**
	 * Request statuses that `_wp_privacy_account_request_confirmed()` will act on.
	 *
	 * @var string[]
	 */
	private const CONFIRMABLE_STATUSES = array( 'request-pending', 'request-failed' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-privacy/confirm-request';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Confirm Request', 'abilities-catalog' ),
			'description'         => __( 'Administratively confirms a personal-data request in request-pending or request-failed state, setting its status to request-confirmed.', 'abilities-catalog' ),
			'category'            => 'privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the user_request post to confirm. Obtain it from og-privacy/list-export-requests or og-privacy/list-erase-requests.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'request_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'request_id', 'status', 'action_name' ),
				'properties'           => array(
					'request_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The confirmed request ID.', 'abilities-catalog' ),
					),
					'status'      => array(
						'type'        => 'string',
						'description' => __( 'The resulting request status.', 'abilities-catalog' ),
					),
					'action_name' => array(
						'type'        => 'string',
						'description' => __( 'The request action name (export_personal_data or remove_personal_data).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => false,
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
	 * Every successful caller holds `manage_privacy_options`, so this coarse floor
	 * is never weaker than core. The per-type cap (and the missing-request 404) is
	 * enforced in `execute()`, keeping this guard object-independent so the specific
	 * error surfaces instead of the generic denial. The wrapped core function
	 * performs no capability check.
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
	 * Executes the ability by confirming the request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{request_id:int,status:string,action_name:string}|\WP_Error
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

		$request = wp_get_user_request( $request_id );
		if ( ! $request instanceof WP_User_Request ) {
			return new WP_Error(
				'invalid_request',
				__( 'No personal-data request found for that ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Validate the request type and enforce the per-type capability (relocated
		// from permission_callback) before the confirmable-state check, so an
		// unauthorized-for-type caller is not told whether the request is confirmable.
		switch ( $request->action_name ) {
			case 'export_personal_data':
				if ( ! current_user_can( 'export_others_personal_data' ) ) {
					return new WP_Error(
						'rest_cannot_confirm',
						__( 'Sorry, you are not allowed to confirm this request.', 'abilities-catalog' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			case 'remove_personal_data':
				if ( ! ( current_user_can( 'erase_others_personal_data' ) && current_user_can( 'delete_users' ) ) ) {
					return new WP_Error(
						'rest_cannot_confirm',
						__( 'Sorry, you are not allowed to confirm this request.', 'abilities-catalog' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			default:
				return new WP_Error(
					'unsupported_request_type',
					__( 'This request type cannot be confirmed by this ability.', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
		}

		if ( ! in_array( $request->status, self::CONFIRMABLE_STATUSES, true ) ) {
			return new WP_Error(
				'not_confirmable',
				__( 'This request cannot be confirmed in its current state.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		_wp_privacy_account_request_confirmed( $request_id );

		$updated = wp_get_user_request( $request_id );

		return array(
			'request_id'  => $request_id,
			'status'      => $updated instanceof WP_User_Request ? (string) $updated->status : '',
			'action_name' => (string) $request->action_name,
		);
	}
}
