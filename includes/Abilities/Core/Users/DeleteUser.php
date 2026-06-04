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
			'description'         => __( 'Permanently deletes a user by ID and reassigns their content to another existing user. Irreversible: users do not support trashing. The reassign target is required and must be a different, existing user.', 'abilities-catalog' ),
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
					'deleted'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the user was deleted.', 'abilities-catalog' ),
					),
					'id'            => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the deleted user.', 'abilities-catalog' ),
					),
					'reassigned_to' => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the user that received the deleted user\'s content.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'users.php',
			),
		);
	}

	/**
	 * Permission check: object-level `delete_user` on the target user.
	 *
	 * Also enforces the data-loss guard at the authorization boundary: the request
	 * is denied unless `reassign` is a positive integer, names an existing user, and
	 * differs from the user being deleted. This prevents authorizing a delete that
	 * would destroy the user's content instead of reassigning it.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the user and reassign is valid.
	 */
	public function hasPermission( $input ): bool {
		$input    = is_array( $input ) ? $input : array();
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$reassign = isset( $input['reassign'] ) ? absint( $input['reassign'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		if ( ! $this->isValidReassign( $reassign, $id ) ) {
			return false;
		}

		return current_user_can( 'delete_user', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Re-validates the data-loss guard, then forces `force=true` and sets the
	 * validated `reassign` target so the deleted user's content is reassigned. A
	 * REST error (e.g. multisite unsupported, invalid reassign) is surfaced unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deletion result, or the REST error.
	 */
	public function execute( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$id       = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		$reassign = isset( $input['reassign'] ) ? absint( $input['reassign'] ) : 0;

		if ( $id <= 0 ) {
			return new WP_Error(
				'webmcp_invalid_user',
				__( 'A valid user ID is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isValidReassign( $reassign, $id ) ) {
			return new WP_Error(
				'webmcp_invalid_reassign',
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

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted'       => (bool) ( $data['deleted'] ?? false ),
			'id'            => $id,
			'reassigned_to' => $reassign,
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
