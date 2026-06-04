<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `users/get-current-user`.
 *
 * Wraps `GET /wp/v2/users/me` via `rest_do_request()` and shapes the response
 * into the same flat field set as `users/get-user`. Requires a logged-in user.
 * Never outputs any password value. Read-only.
 *
 * @since 0.1.0
 */
final class GetCurrentUser implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'users/get-current-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Current User', 'abilities-catalog'),
			'description'         => __('Returns the profile of the currently logged-in user.', 'abilities-catalog'),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'context' => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (own profile fields).', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'name'),
				'properties'           => array(
					'id'              => array(
						'type'        => 'integer',
						'description' => __('The user ID.', 'abilities-catalog'),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __('The display name for the user.', 'abilities-catalog'),
					),
					'slug'            => array(
						'type'        => 'string',
						'description' => __('An alphanumeric identifier for the user.', 'abilities-catalog'),
					),
					'email'           => array(
						'type'        => array('string', 'null'),
						'description' => __('The email address of the current user.', 'abilities-catalog'),
					),
					'roles'           => array(
						'type'        => array('array', 'null'),
						'items'       => array('type' => 'string'),
						'description' => __('Roles assigned to the current user.', 'abilities-catalog'),
					),
					'capabilities'    => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __('Capabilities of the current user.', 'abilities-catalog'),
					),
					'registered_date' => array(
						'type'        => array('string', 'null'),
						'description' => __('The registration date of the current user.', 'abilities-catalog'),
					),
					'url'             => array(
						'type'        => 'string',
						'description' => __('The website URL for the current user.', 'abilities-catalog'),
					),
					'description'     => array(
						'type'        => 'string',
						'description' => __('The biographical description for the current user.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: a user must be logged in.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if there is a logged-in user.
	 */
	public function hasPermission($input): bool
	{
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat user fields, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request('GET', '/wp/v2/users/me');
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return GetUser::mapUser($data, get_current_user_id());
	}
}
