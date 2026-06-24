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
 * Destructive T2 write ability: `users/delete-user`.
 *
 * Wraps `DELETE /wp/v2/users/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the user. Users do not support trashing, so this is an
 * irreversible operation. The `reassign` parameter is REQUIRED and guarded as a
 * data-loss safeguard: it must be a positive integer, name an existing user, and
 * differ from the user being deleted. This forces the deleted user's content to be
 * reassigned to another account rather than destroyed alongside the user.
 *
 * The `permission_callback` encodes the catalog's object-level `delete_user`
 * capability, mirroring the REST controller. Because the annotations mark this as a
 * destructive write, the Registry registers it but the adapter exposes it to the
 * browser only when BOTH the write and destructive settings are on.
 *
 * @since 0.3.0
 */
final class DeleteUser implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/delete-user';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete User', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a user by ID and reassigns their content to another existing user. Irreversible: users do not support trashing. The reassign target is required and must be a different, existing user. Single-site only: the wrapped route hard-fails on multisite with a 501 error.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The user ID to delete permanently.', 'abilities-catalog' ),
					),
					'reassign' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Required. The ID of an existing, different user to reassign the deleted user\'s content to. Content is reassigned, never destroyed.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id', 'reassign' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id', 'reassigned_to' ),
				'properties'           => array(
					'deleted'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the user was deleted.', 'abilities-catalog' ),
					),
					'id'                => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the deleted user.', 'abilities-catalog' ),
					),
					'reassigned_to'     => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the user that received the deleted user\'s content.', 'abilities-catalog' ),
					),
					'previous_username' => array(
						'type'        => 'string',
						'description' => __( 'The login username of the deleted user.', 'abilities-catalog' ),
					),
					'previous_name'     => array(
						'type'        => 'string',
						'description' => __( 'The display name of the deleted user.', 'abilities-catalog' ),
					),
					'previous_email'    => array(
						'type'        => 'string',
						'description' => __( 'The email address of the deleted user.', 'abilities-catalog' ),
					),
					'previous_slug'     => array(
						'type'        => 'string',
						'description' => __( 'The URL slug (nicename) of the deleted user.', 'abilities-catalog' ),
					),
					'previous_roles'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The roles held by the deleted user.', 'abilities-catalog' ),
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
				'screen'            => 'users.php',
			),
		);
	}

	/**
	 * Permission check: the object-independent `delete_users` capability.
	 *
	 * `delete_user` on any object maps to the `delete_users` primitive with no
	 * self-exception, so `delete_users` is the object-independent floor every
	 * successful caller holds and requiring it here is never stricter than core. The
	 * object-level decision and the reassign data-loss guard are enforced in
	 * `execute()`: the wrapped `DELETE /wp/v2/users/<id>` route re-checks `delete_user`
	 * (surfacing `rest_user_invalid_id` 404 / `rest_user_cannot_delete` 403), and
	 * {@see self::isValidReassign()} is re-run before the delete (surfacing a specific
	 * `abilities_catalog_invalid_reassign` 400). Keeping those checks only here would collapse
	 * each into the generic denial the Abilities API substitutes for a non-`true`
	 * return.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete users.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'delete_users' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * On multisite the core REST users controller refuses every DELETE
	 * (`rest_cannot_delete`, 501), so this returns a recovery-oriented `WP_Error`
	 * before dispatching, pointing the caller at `network/remove-user-from-site`
	 * for per-site removal. On single site it re-validates the data-loss guard,
	 * then forces `force=true` and sets the validated `reassign` target so the
	 * deleted user's content is reassigned. A REST error (e.g. invalid reassign)
	 * is surfaced unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion result, or a WP_Error.
	 */
	public function execute( $input ) {
		if ( is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_delete_user_multisite',
				__( 'Deleting a user is disabled on multisite. To remove a user from one site, use network/remove-user-from-site.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$input    = is_array( $input ) ? $input : array();
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$reassign = isset( $input['reassign'] ) ? absint( $input['reassign'] ) : 0;

		if ( $id <= 0 ) {
			return new WP_Error(
				'abilities_catalog_invalid_user',
				__( 'A valid user ID is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isValidReassign( $reassign, $id ) ) {
			return new WP_Error(
				'abilities_catalog_invalid_reassign',
				__( 'The reassign target must be a positive ID of an existing user different from the user being deleted.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/users/' . $id );
		$request->set_param( 'force', true );
		$request->set_param( 'reassign', $reassign );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data     = rest_get_server()->response_to_data( $response, false );
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();
		$roles    = is_array( $previous['roles'] ?? null ) ? array_values( array_map( 'strval', $previous['roles'] ) ) : array();

		return array(
			'deleted'           => (bool) ( $data['deleted'] ?? false ),
			'id'                => $id,
			'reassigned_to'     => $reassign,
			'previous_username' => (string) ( $previous['username'] ?? '' ),
			'previous_name'     => (string) ( $previous['name'] ?? '' ),
			'previous_email'    => (string) ( $previous['email'] ?? '' ),
			'previous_slug'     => (string) ( $previous['slug'] ?? '' ),
			'previous_roles'    => $roles,
		);
	}

	/**
	 * Validates the reassign target as a data-loss safeguard.
	 *
	 * @param int $reassign The reassign target user ID.
	 * @param int $id       The user ID being deleted.
	 * @return bool True if reassign is a positive, existing, different user.
	 */
	private function isValidReassign( int $reassign, int $id ): bool {
		if ( $reassign <= 0 ) {
			return false;
		}

		if ( $reassign === $id ) {
			return false;
		}

		return false !== get_userdata( $reassign );
	}
}
