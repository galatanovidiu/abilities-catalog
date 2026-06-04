<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use GalatanOvidiu\AbilitiesCatalog\Support\SecretSafeError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 write ability: `users/update-current-user`.
 *
 * Wraps `POST /wp/v2/users/me` via `rest_do_request()` and returns the current
 * user's id, name, email, and roles. Resolves the target as the current user and
 * encodes `edit_user` on that object, mirroring the users controller's
 * `update_current_item_permissions_check`. When the input includes `roles`, it
 * ALSO requires `promote_user` on the current user — without it a caller could
 * escalate their own role. The REST route re-checks every capability underneath.
 *
 * Secret-bearing: the input may carry a plaintext `password`. The error path is
 * routed through {@see SecretSafeError::redact()} so a rejected password (or any
 * other submitted value) can never be echoed back to the browser. No input is
 * logged. The password is never returned in the output.
 *
 * @since 0.3.0
 */
final class UpdateCurrentUser implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/update-current-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Current User', 'abilities-catalog' ),
			'description'         => __( 'Updates the currently logged-in user\'s own profile. Changing roles requires the promote capability.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
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
					'email'      => array(
						'type'        => 'string',
						'description' => __( 'The email address for the user.', 'abilities-catalog' ),
					),
					'url'        => array(
						'type'        => 'string',
						'description' => __( 'The website URL for the user.', 'abilities-catalog' ),
					),
					'locale'     => array(
						'type'        => 'string',
						'description' => __( 'Locale for the user.', 'abilities-catalog' ),
					),
					'password'   => array(
						'type'        => 'string',
						'description' => __( 'A new password for the user (write-only; never returned).', 'abilities-catalog' ),
					),
					'roles'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Roles to assign (requires the promote capability).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'    => array(
						'type'        => 'integer',
						'description' => __( 'The user ID.', 'abilities-catalog' ),
					),
					'name'  => array(
						'type'        => 'string',
						'description' => __( 'The display name for the user.', 'abilities-catalog' ),
					),
					'email' => array(
						'type'        => 'string',
						'description' => __( 'The email address for the user.', 'abilities-catalog' ),
					),
					'roles' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Roles assigned to the user.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'profile.php',
			),
		);
	}

	/**
	 * Permission check: edit access to own account, plus promote for roles.
	 *
	 * Resolves the target as `get_current_user_id()` and mirrors the users
	 * controller (`edit_user` on the current user). When the input changes roles,
	 * the controller also requires `promote_user`; this check enforces that
	 * branch up front to block self role escalation.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update their own account.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = get_current_user_id();

		if ( $id <= 0 ) {
			return false;
		}

		if ( ! current_user_can( 'edit_user', $id ) ) {
			return false;
		}

		return ! isset( $input['roles'] ) || current_user_can( 'promote_user', $id );
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
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );

		// String fields pass through to the REST route, which sanitizes them.
		foreach ( array( 'name', 'first_name', 'last_name', 'email', 'url', 'locale', 'password' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

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
			'id'    => (int) ( $data['id'] ?? get_current_user_id() ),
			'name'  => (string) ( $data['name'] ?? '' ),
			'email' => (string) ( $data['email'] ?? '' ),
			'roles' => isset( $data['roles'] ) ? array_map( 'strval', (array) $data['roles'] ) : array(),
		);
	}
}
