<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Privacy;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_User_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 non-destructive write ability: `privacy/confirm-request`.
 *
 * Administratively confirms a pending personal-data request by wrapping the
 * core function `_wp_privacy_account_request_confirmed($request_id)`, which
 * sets the request status to `request-confirmed`. The core function acts only
 * on a request currently in `request-pending` or `request-failed` state and is
 * a no-op otherwise (wp-includes/user.php:4216).
 *
 * Confirming is normally done by the data subject, not an admin: core fires
 * `_wp_privacy_account_request_confirmed()` from the public `confirmaction`
 * flow in wp-login.php, gated by a per-request key
 * (`wp_validate_user_request_key`), NOT by a capability (wp-login.php:1246).
 * There is no core admin handler that calls this function behind a capability
 * gate. So this ability is an administrative override of the email-key proof,
 * and there is no core admin cap to mirror exactly.
 *
 * The cap is therefore floored at the strictest correct guard: the current user
 * must hold `manage_privacy_options` AND the same capability that gates the
 * wp-admin screen where requests of that type are managed:
 *   - `export_personal_data` → `export_others_personal_data`
 *     (wp-admin/export-personal-data.php:12)
 *   - `remove_personal_data` → `erase_others_personal_data` AND `delete_users`
 *     (wp-admin/erase-personal-data.php:12)
 * Unknown action types are denied.
 *
 * CAP UNCERTAINTY: no core admin handler gates this exact operation, so the
 * `manage_privacy_options` floor is an added guard, not a core mirror. It is
 * strict (never weaker than the per-type screen cap), but it could be stricter
 * than strictly necessary. Flagged for review.
 *
 * @since 0.3.0
 */
final class ConfirmRequest implements Ability
{
	/**
	 * Request statuses that `_wp_privacy_account_request_confirmed()` will act on.
	 *
	 * @var string[]
	 */
	private const CONFIRMABLE_STATUSES = array('request-pending', 'request-failed');

	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'privacy/confirm-request';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Confirm Request', 'abilities-catalog'),
			'description'         => __('Administratively confirms a pending personal-data request, setting its status to request-confirmed.', 'abilities-catalog'),
			'category'            => 'privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('The ID of the user_request post to confirm.', 'abilities-catalog'),
					),
				),
				'required'             => array('request_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('request_id', 'status'),
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'description' => __('The confirmed request ID.', 'abilities-catalog'),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __('The resulting request status.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: branches on the request action type.
	 *
	 * Loads the request and floors the cap at `manage_privacy_options` plus the
	 * per-type screen cap (export → `export_others_personal_data`; erase →
	 * `erase_others_personal_data` AND `delete_users`). Unknown or missing
	 * requests are denied. The wrapped core function performs no capability
	 * check, so this is the hard authorization guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may confirm this request.
	 */
	public function hasPermission($input): bool
	{
		$input      = is_array($input) ? $input : array();
		$request_id = isset($input['request_id']) ? absint($input['request_id']) : 0;
		if ($request_id < 1) {
			return false;
		}

		if (!current_user_can('manage_privacy_options')) {
			return false;
		}

		$request = wp_get_user_request($request_id);
		if (!$request instanceof WP_User_Request) {
			return false;
		}

		switch ($request->action_name) {
			case 'export_personal_data':
				return current_user_can('export_others_personal_data');
			case 'remove_personal_data':
				return current_user_can('erase_others_personal_data') && current_user_can('delete_users');
			default:
				return false;
		}
	}

	/**
	 * Executes the ability by confirming the request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{request_id:int,status:string}|\WP_Error
	 */
	public function execute($input)
	{
		$input      = is_array($input) ? $input : array();
		$request_id = isset($input['request_id']) ? absint($input['request_id']) : 0;

		if ($request_id < 1) {
			return new WP_Error(
				'invalid_request',
				__('A valid request ID is required.', 'abilities-catalog'),
				array('status' => 400)
			);
		}

		$request = wp_get_user_request($request_id);
		if (!$request instanceof WP_User_Request) {
			return new WP_Error(
				'invalid_request',
				__('No personal-data request found for that ID.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		if (!in_array($request->status, self::CONFIRMABLE_STATUSES, true)) {
			return new WP_Error(
				'not_confirmable',
				__('This request cannot be confirmed in its current state.', 'abilities-catalog'),
				array('status' => 409)
			);
		}

		_wp_privacy_account_request_confirmed($request_id);

		$updated = wp_get_user_request($request_id);

		return array(
			'request_id' => $request_id,
			'status'     => $updated instanceof WP_User_Request ? (string) $updated->status : '',
		);
	}
}
