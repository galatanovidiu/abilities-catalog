<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
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
final class DeleteApplicationPassword implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/delete-application-password';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Application Password', 'abilities-catalog' ),
			'description'         => __( 'Permanently revokes a single application password by its UUID for a user. Irreversible: the credential stops working immediately.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The user ID that owns the application password. Defaults to the current user.', 'abilities-catalog' ),
					),
					'uuid'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The UUID of the application password to revoke. Use users/list-application-passwords to discover existing UUIDs.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'uuid' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'uuid', 'name', 'app_id' ),
				'properties'           => array(
					'deleted'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the application password was revoked.', 'abilities-catalog' ),
					),
					'uuid'      => array(
						'type'        => 'string',
						'description' => __( 'The UUID of the revoked application password.', 'abilities-catalog' ),
					),
					'name'      => array(
						'type'        => 'string',
						'description' => __( 'The human-readable name of the revoked application password.', 'abilities-catalog' ),
					),
					'app_id'    => array(
						'type'        => 'string',
						'description' => __( 'The application UUID of the revoked credential, if one was set.', 'abilities-catalog' ),
					),
					'created'   => array(
						'type'        => 'string',
						'description' => __( 'When the revoked application password was created (GMT, ISO 8601).', 'abilities-catalog' ),
					),
					'last_used' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'When the revoked application password was last used (GMT, ISO 8601), or null if never used.', 'abilities-catalog' ),
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
					'scope' => 'user',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: a logged-in user; the route enforces the object.
	 *
	 * Application passwords are user-scoped, so a logged-in user is the
	 * object-independent floor every successful caller holds — `delete_app_password`
	 * is never granted to a logged-out request, so this is never stricter than core.
	 * The object-level decision (own credentials are allowed, another user requires
	 * `edit_user`) is enforced by the wrapped
	 * `DELETE /wp/v2/users/<id>/application-passwords/<uuid>` route's
	 * `delete_item_permissions_check`, so its specific errors (`rest_user_invalid_id`
	 * 404, `rest_cannot_delete_application_password` 403) reach the caller instead of
	 * the generic denial the Abilities API substitutes for a non-`true` return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if a user is logged in.
	 */
	public function hasPermission( $input ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * A REST error (e.g. unknown uuid, app passwords unavailable) is surfaced unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion result, including a snapshot of the revoked credential, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$user_id = $this->resolveUserId( $input );
		$uuid    = isset( $input['uuid'] ) ? (string) $input['uuid'] : '';

		if ( $user_id <= 0 || '' === $uuid ) {
			return new WP_Error(
				'abilities_catalog_invalid_application_password',
				__( 'A valid user ID and application-password UUID are required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$request  = new WP_REST_Request( 'DELETE', '/wp/v2/users/' . $user_id . '/application-passwords/' . $uuid );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data     = rest_get_server()->response_to_data( $response, false );
		$data     = is_array( $data ) ? $data : array();
		$previous = isset( $data['previous'] ) && is_array( $data['previous'] ) ? $data['previous'] : array();

		return array(
			'deleted'   => (bool) ( $data['deleted'] ?? false ),
			'uuid'      => $uuid,
			'name'      => (string) ( $previous['name'] ?? '' ),
			'app_id'    => (string) ( $previous['app_id'] ?? '' ),
			'created'   => (string) ( $previous['created'] ?? '' ),
			'last_used' => $previous['last_used'] ?? null,
		);
	}

	/**
	 * Resolves the target user ID, defaulting to the current user.
	 *
	 * @param mixed $input The validated input data.
	 * @return int The resolved user ID, or 0 when none is available.
	 */
	private function resolveUserId( $input ): int {
		$input = is_array( $input ) ? $input : array();

		if ( isset( $input['user_id'] ) ) {
			return absint( $input['user_id'] );
		}

		return get_current_user_id();
	}
}
