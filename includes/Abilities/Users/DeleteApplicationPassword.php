<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Users;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Destructive T2 write ability: `users/delete-application-password`.
 *
 * Wraps `DELETE /wp/v2/users/<user_id>/application-passwords/<uuid>` via
 * `rest_do_request()`, permanently revoking a single application password. The
 * action is irreversible: the credential stops working immediately and cannot be
 * restored. The `permission_callback` encodes the catalog's object-level
 * `delete_app_password` capability (resolved on user ID and uuid), mirroring the
 * REST controller.
 *
 * Because the annotations mark this as a destructive write, the Registry registers
 * it but the adapter exposes it to the browser only when BOTH the write and
 * destructive settings are on.
 *
 * @since 0.3.0
 */
final class DeleteApplicationPassword implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'users/delete-application-password';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Delete Application Password', 'abilities-catalog'),
			'description'         => __('Permanently revokes a single application password by its UUID for a user. Irreversible: the credential stops working immediately.', 'abilities-catalog'),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('The user ID that owns the application password. Defaults to the current user.', 'abilities-catalog'),
					),
					'uuid'    => array(
						'type'        => 'string',
						'description' => __('The UUID of the application password to revoke.', 'abilities-catalog'),
					),
				),
				'required'             => array('uuid'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('deleted', 'uuid'),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __('Whether the application password was revoked.', 'abilities-catalog'),
					),
					'uuid'    => array(
						'type'        => 'string',
						'description' => __('The UUID of the revoked application password.', 'abilities-catalog'),
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
	 * Permission check: object-level `delete_app_password` on the target user/uuid.
	 *
	 * Mirrors the REST controller's `delete_item_permissions_check`, passing both the
	 * resolved user ID and the uuid so the meta-capability map can scope the check to
	 * the specific credential.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may revoke the application password.
	 */
	public function hasPermission($input): bool
	{
		$input   = is_array($input) ? $input : array();
		$user_id = $this->resolveUserId($input);
		$uuid    = isset($input['uuid']) ? (string) $input['uuid'] : '';

		if ($user_id <= 0 || '' === $uuid) {
			return false;
		}

		return current_user_can('delete_app_password', $user_id, $uuid);
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * A REST error (e.g. unknown uuid, app passwords unavailable) is surfaced unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion result, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$user_id = $this->resolveUserId($input);
		$uuid    = isset($input['uuid']) ? (string) $input['uuid'] : '';

		if ($user_id <= 0 || '' === $uuid) {
			return new WP_Error(
				'webmcp_invalid_application_password',
				__('A valid user ID and application-password UUID are required.', 'abilities-catalog'),
				array('status' => 400)
			);
		}

		$request  = new WP_REST_Request('DELETE', '/wp/v2/users/' . $user_id . '/application-passwords/' . $uuid);
		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'deleted' => (bool) ($data['deleted'] ?? false),
			'uuid'    => $uuid,
		);
	}

	/**
	 * Resolves the target user ID, defaulting to the current user.
	 *
	 * @param mixed $input The validated input data.
	 * @return int The resolved user ID, or 0 when none is available.
	 */
	private function resolveUserId($input): int
	{
		$input = is_array($input) ? $input : array();

		if (isset($input['user_id'])) {
			return absint($input['user_id']);
		}

		return get_current_user_id();
	}
}
