<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use GalatanOvidiu\AbilitiesCatalog\Support\SecretSafeError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 write ability: `og-users/create-user`.
 *
 * Wraps `POST /wp/v2/users` via `rest_do_request()` and returns the new user's
 * id, username, email, and roles. Encodes the catalog capability `create_users`,
 * mirroring the users controller's `create_item_permissions_check`. The REST
 * route re-checks the capability underneath and sanitizes every field.
 *
 * Secret-bearing: the input carries a plaintext `password`. The error path is
 * routed through {@see SecretSafeError::redact()} so a rejected password (or any
 * other submitted value) can never be echoed back to the browser. No input is
 * logged. The password is never returned in the output.
 *
 * @since 0.3.0
 */
final class CreateUser implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/create-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create User', 'abilities-catalog' ),
			'description'         => __( 'Creates a new user account with the given username, email, and password. This REST-backed create does NOT send the wp-admin "Send User Notification" email; delivering credentials to the new user is the caller\'s responsibility. Creating a user is not auto-reversible; pair with og-users/delete-user to undo.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'username'   => array(
						'type'        => 'string',
						'description' => __( 'The login name for the user.', 'abilities-catalog' ),
					),
					'email'      => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => __( 'The email address for the user.', 'abilities-catalog' ),
					),
					'password'   => array(
						'type'        => 'string',
						'description' => __( 'The password for the user (write-only; never returned).', 'abilities-catalog' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The display name for the user.', 'abilities-catalog' ),
					),
					'first_name' => array(
						'type'        => 'string',
						'description' => __( 'First name for the user.', 'abilities-catalog' ),
					),
					'last_name'  => array(
						'type'        => 'string',
						'description' => __( 'Last name for the user.', 'abilities-catalog' ),
					),
					'roles'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Role slugs to assign to the user. Assignment is limited to roles the current user is allowed to grant; unknown or non-editable roles are rejected. Omit to assign the site default role; pass an empty array for a roleless user.', 'abilities-catalog' ),
					),
					'url'        => array(
						'type'        => 'string',
						'format'      => 'uri',
						'description' => __( 'The website URL for the user.', 'abilities-catalog' ),
					),
					'locale'     => array(
						'type'        => 'string',
						'description' => __( 'Locale for the user.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'username', 'email', 'password' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The new user ID.', 'abilities-catalog' ),
					),
					'username' => array(
						'type'        => 'string',
						'description' => __( 'The login name of the new user.', 'abilities-catalog' ),
					),
					'email'    => array(
						'type'        => 'string',
						'description' => __( 'The email address of the new user.', 'abilities-catalog' ),
					),
					'roles'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Roles assigned to the new user.', 'abilities-catalog' ),
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
					'scope' => 'site',
				),
				'show_in_rest'      => true,
				'screen'            => 'users.php',
			),
		);
	}

	/**
	 * Permission check: the current user may create users.
	 *
	 * Mirrors the users controller's `create_item_permissions_check`, which
	 * requires the `create_users` capability.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create a user.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'create_users' );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * The error path is redacted so no submitted value (including the password)
	 * is echoed to the caller. The password is never returned on success.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new user's id, username, email, roles, or a redacted error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/users' );

		// String fields pass through to the REST route, which validates and
		// sanitizes them. Forward every field that is set, including an explicit
		// empty string, so core can apply its own rules (an empty password is
		// rejected with rest_user_invalid_password; an empty locale is allowed).
		foreach ( array( 'username', 'email', 'password', 'name', 'first_name', 'last_name', 'url', 'locale' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		// Forward roles whenever the key is present and an array, including the
		// empty array, so a caller can request a roleless user instead of having
		// core fall back to the default role.
		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			$request->set_param( 'roles', array_map( 'strval', $input['roles'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return SecretSafeError::redact( RestError::from( $response ) );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'       => (int) ( $data['id'] ?? 0 ),
			'username' => (string) ( $data['username'] ?? '' ),
			'email'    => (string) ( $data['email'] ?? '' ),
			'roles'    => isset( $data['roles'] ) ? array_map( 'strval', (array) $data['roles'] ) : array(),
		);
	}
}
