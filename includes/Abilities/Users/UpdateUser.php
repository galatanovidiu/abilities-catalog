<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Users;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\SecretSafeError;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 write ability: `users/update-user`.
 *
 * Wraps `POST /wp/v2/users/<id>` via `rest_do_request()` and returns the user's
 * id, name, email, and roles. Encodes the catalog capability `edit_user` on the
 * object, mirroring the users controller's `update_item_permissions_check`.
 * When the input includes `roles`, it ALSO requires `promote_user` on the
 * object — without it a caller could escalate a user's role through an edit. The
 * REST route re-checks every capability underneath.
 *
 * Secret-bearing: the input may carry a plaintext `password`. The error path is
 * routed through {@see SecretSafeError::redact()} so a rejected password (or any
 * other submitted value) can never be echoed back to the browser. No input is
 * logged. The password is never returned in the output.
 *
 * @since 0.3.0
 */
final class UpdateUser implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'users/update-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Update User', 'abilities-catalog'),
			'description'         => __('Updates an existing user by ID. Changing roles requires the promote capability.', 'abilities-catalog'),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'         => array(
						'type'        => 'integer',
						'description' => __('The ID of the user to update.', 'abilities-catalog'),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __('The display name for the user.', 'abilities-catalog'),
					),
					'first_name' => array(
						'type'        => 'string',
						'description' => __('First name for the user.', 'abilities-catalog'),
					),
					'last_name'  => array(
						'type'        => 'string',
						'description' => __('Last name for the user.', 'abilities-catalog'),
					),
					'email'      => array(
						'type'        => 'string',
						'description' => __('The email address for the user.', 'abilities-catalog'),
					),
					'url'        => array(
						'type'        => 'string',
						'description' => __('The website URL for the user.', 'abilities-catalog'),
					),
					'locale'     => array(
						'type'        => 'string',
						'description' => __('Locale for the user.', 'abilities-catalog'),
					),
					'password'   => array(
						'type'        => 'string',
						'description' => __('A new password for the user (write-only; never returned).', 'abilities-catalog'),
					),
					'roles'      => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => __('Roles to assign to the user (requires the promote capability).', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id'),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => __('The user ID.', 'abilities-catalog'),
					),
					'name'  => array(
						'type'        => 'string',
						'description' => __('The display name for the user.', 'abilities-catalog'),
					),
					'email' => array(
						'type'        => 'string',
						'description' => __('The email address for the user.', 'abilities-catalog'),
					),
					'roles' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => __('Roles assigned to the user.', 'abilities-catalog'),
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
				'screen'       => 'user-edit.php?user_id={id}',
			),
		);
	}

	/**
	 * Permission check: edit access to the target user, plus promote for roles.
	 *
	 * Mirrors the users controller's `update_item_permissions_check`
	 * (`edit_user` on the object). When the input changes roles, the controller
	 * also requires `promote_user`; this check enforces that branch up front to
	 * block role escalation through an edit.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the target user.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		if (!current_user_can('edit_user', $id)) {
			return false;
		}

		if (isset($input['roles']) && !current_user_can('promote_user', $id)) {
			return false;
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * The error path is redacted so no submitted value (including the password)
	 * is echoed to the caller. The password is never returned on success.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The user's id, name, email, roles, or a redacted error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('POST', '/wp/v2/users/' . $id);

		// String fields pass through to the REST route, which sanitizes them.
		foreach (array('name', 'first_name', 'last_name', 'email', 'url', 'locale', 'password') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
		}

		if (isset($input['roles']) && is_array($input['roles'])) {
			$request->set_param('roles', array_map('strval', $input['roles']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return SecretSafeError::redact($response->as_error());
		}

		$data = rest_get_server()->response_to_data($response, false);
		$data = is_array($data) ? $data : array();

		return array(
			'id'    => (int) ($data['id'] ?? $id),
			'name'  => (string) ($data['name'] ?? ''),
			'email' => (string) ($data['email'] ?? ''),
			'roles' => isset($data['roles']) ? array_map('strval', (array) $data['roles']) : array(),
		);
	}
}
