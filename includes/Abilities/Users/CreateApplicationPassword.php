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
 * T2 write ability: `users/create-application-password`.
 *
 * Wraps `POST /wp/v2/users/<user_id>/application-passwords` via
 * `rest_do_request()` and returns the new record's uuid, app_id, name, and the
 * one-time plaintext `password`. Encodes the object-level catalog capability
 * `create_app_password` on the resolved user, mirroring the controller's
 * `create_item_permissions_check`.
 *
 * Secret-bearing (both directions):
 * - The newly generated plaintext password is returned ONCE in the success body
 *   (it is intentionally surfaced one time; WordPress cannot show it again). It
 *   is declared in `output_schema` and returned, but never logged.
 * - The error path is routed through {@see SecretSafeError::redact()} so no
 *   submitted value is echoed back to the browser. No input is logged.
 *
 * @since 0.3.0
 */
final class CreateApplicationPassword implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'users/create-application-password';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Application Password', 'abilities-catalog'),
			'description'         => __('Creates a new application password for a user. The plaintext password is returned once and cannot be retrieved again.', 'abilities-catalog'),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'description' => __('The user ID. Defaults to the current user.', 'abilities-catalog'),
					),
					'name'    => array(
						'type'        => 'string',
						'description' => __('A human-readable name for the application password.', 'abilities-catalog'),
					),
					'app_id'  => array(
						'type'        => 'string',
						'description' => __('An optional UUID identifying the application.', 'abilities-catalog'),
					),
				),
				'required'             => array('name'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('uuid', 'password'),
				'properties'           => array(
					'uuid'     => array(
						'type'        => 'string',
						'description' => __('The unique identifier for the application password.', 'abilities-catalog'),
					),
					'app_id'   => array(
						'type'        => 'string',
						'description' => __('The application UUID, if one was provided.', 'abilities-catalog'),
					),
					'name'     => array(
						'type'        => 'string',
						'description' => __('The name of the application password.', 'abilities-catalog'),
					),
					'password' => array(
						'type'        => 'string',
						'description' => __('The generated plaintext password. Returned once only; cannot be retrieved again.', 'abilities-catalog'),
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
	 * Permission check: the current user may create an app password for the target.
	 *
	 * Encodes the controller's object-level `create_app_password` capability on
	 * the resolved user ID.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the application password.
	 */
	public function hasPermission($input): bool
	{
		$user_id = $this->resolveUserId($input);

		if ($user_id <= 0) {
			return false;
		}

		return current_user_can('create_app_password', $user_id);
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * Returns the one-time plaintext password in the success body. The password
	 * is never logged. The error path is redacted so no submitted value is
	 * echoed to the caller.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new record (uuid, app_id, name, password), or a redacted error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$user_id = $this->resolveUserId($input);
		$request = new WP_REST_Request('POST', '/wp/v2/users/' . $user_id . '/application-passwords');

		if (isset($input['name']) && '' !== $input['name']) {
			$request->set_param('name', (string) $input['name']);
		}

		if (isset($input['app_id']) && '' !== $input['app_id']) {
			$request->set_param('app_id', (string) $input['app_id']);
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return SecretSafeError::redact($response->as_error());
		}

		$data = rest_get_server()->response_to_data($response, false);
		$data = is_array($data) ? $data : array();

		return array(
			'uuid'     => (string) ($data['uuid'] ?? ''),
			'app_id'   => (string) ($data['app_id'] ?? ''),
			'name'     => (string) ($data['name'] ?? ''),
			'password' => (string) ($data['password'] ?? ''),
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
