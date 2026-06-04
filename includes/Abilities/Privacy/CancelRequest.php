<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Privacy;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_User_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `privacy/cancel-request`.
 *
 * Permanently deletes a personal-data request record by wrapping the core
 * function `wp_delete_post($request_id, true)`. The deleted thing is the
 * `user_request` custom-post-type post that records the export or erasure
 * request — NOT any exported or erased personal data. Deleting the record is
 * how the wp-admin Export / Erase Personal Data screens "remove" a request
 * row (`_wp_personal_data_handle_actions()`, the `remove` action, calls
 * `wp_delete_post($request_id, true)` in wp-admin/includes/privacy-tools.php).
 *
 * The deletion is permanent (force delete bypasses Trash), so this ability is
 * annotated destructive. It is a net-new wrapper: there is no REST route for
 * user requests, so the core function is called directly. The core function
 * performs no capability check, so the `permission_callback` is the hard
 * authorization guard.
 *
 * The cap branches on the request action type, mirroring the same per-type
 * gating that {@see ConfirmRequest} encodes. The current user must hold
 * `manage_privacy_options` AND the per-type screen cap:
 *   - `export_personal_data` → `export_others_personal_data`
 *     (wp-admin/export-personal-data.php:12)
 *   - `remove_personal_data` → `erase_others_personal_data` AND `delete_users`
 *     (wp-admin/erase-personal-data.php:12)
 * Unknown or missing requests are denied.
 *
 * @since 0.4.0
 */
final class CancelRequest implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'privacy/cancel-request';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Cancel Request', 'abilities-catalog'),
			'description'         => __('Permanently deletes a personal-data request record (the export or erasure request row). Does not delete exported or erased personal data.', 'abilities-catalog'),
			'category'            => 'privacy',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('The ID of the user_request post to delete.', 'abilities-catalog'),
					),
				),
				'required'             => array('request_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('request_id', 'cancelled'),
				'properties'           => array(
					'request_id' => array(
						'type'        => 'integer',
						'description' => __('The deleted request ID.', 'abilities-catalog'),
					),
					'cancelled'  => array(
						'type'        => 'boolean',
						'description' => __('True when the request record was deleted.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
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
	 * `erase_others_personal_data` AND `delete_users`), exactly as
	 * {@see ConfirmRequest} does. Unknown or missing requests are denied. The
	 * wrapped core function performs no capability check, so this is the hard
	 * authorization guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete this request.
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
	 * Executes the ability by permanently deleting the request record.
	 *
	 * @param mixed $input The validated input data.
	 * @return array{request_id:int,cancelled:bool}|\WP_Error
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

		// Confirm the post exists and is a user_request record before deleting.
		$request = wp_get_user_request($request_id);
		if (!$request instanceof WP_User_Request) {
			return new WP_Error(
				'invalid_request',
				__('No personal-data request found for that ID.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		$deleted = wp_delete_post($request_id, true);
		if (false === $deleted || null === $deleted) {
			return new WP_Error(
				'cancel_failed',
				__('The request record could not be deleted.', 'abilities-catalog'),
				array('status' => 500)
			);
		}

		return array(
			'request_id' => $request_id,
			'cancelled'  => true,
		);
	}
}
