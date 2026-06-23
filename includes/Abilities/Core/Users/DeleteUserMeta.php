<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RegisteredMeta;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `users/delete-meta`.
 *
 * Removes one or more custom fields (meta) from a user, deleting all stored
 * values for each named key. It operates only on meta keys registered with
 * `show_in_rest` for users and rejects unknown keys, so internal or sensitive
 * user meta (such as `session_tokens` or `wp_capabilities`) can never be reached.
 * Wraps core `delete_metadata( 'user', ... )` after an object-level `edit_user`
 * resolution and a per-key `delete_user_meta` capability check. This is a data
 * deletion and cannot be undone through this ability; it does not change other
 * user fields. Returns the user `id`, the `deleted` keys, and `edit_link` (the
 * wp-admin profile editor URL); surface `edit_link` so a human can review the user.
 *
 * @since 0.7.0
 */
final class DeleteUserMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/delete-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete User Meta', 'abilities-catalog' ),
			'description'         => __( 'Permanently removes custom fields (meta) from a user by key, deleting all values for each key. Only meta keys registered with show_in_rest for users can be deleted; unknown keys are rejected, so internal user meta such as session_tokens or wp_capabilities is never reachable. This cannot be undone. Returns the user id, the deleted keys, and edit_link — surface edit_link so a human can review the user.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The user ID to delete meta from. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'minItems'    => 1,
						'description' => __( 'The meta keys to remove. Each must be a registered show_in_rest meta key for the user.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id', 'keys' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'deleted', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The user ID.', 'abilities-catalog' ),
					),
					'deleted'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The meta keys that were removed.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the user profile. Surface this so a human can review the user.', 'abilities-catalog' ),
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
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'user-edit.php?user_id={id}',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * This ability calls core directly (no wrapped REST route), so the object-level
	 * guard is enforced in `execute()`: a missing user returns `rest_user_invalid_id`
	 * (404) and a user the caller may not edit returns `rest_cannot_edit` (403),
	 * instead of masking both as a single generic permission error. The per-key
	 * `delete_user_meta` capability is also enforced in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by deleting registered meta from the user.
	 *
	 * Validates every key up front (registered + per-key capability) and deletes
	 * nothing unless all keys pass.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The user id, deleted keys, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$user = get_userdata( $id );
		if ( ! $user ) {
			return new WP_Error( 'rest_user_invalid_id', __( 'Invalid user ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this user.', 'abilities-catalog' ), array( 'status' => 403 ) );
		}

		$keys = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_unique( array_map( 'strval', $input['keys'] ) ) ) : array();
		if ( array() === $keys ) {
			return new WP_Error( 'rest_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = RegisteredMeta::forObject( 'user', 'user' );

		foreach ( $keys as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error(
					'rest_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for users and cannot be deleted.', 'abilities-catalog' ), $name ),
					array( 'status' => 400 )
				);
			}

			// The per-key capability is checked against the storage key, matching
			// core (class-wp-rest-meta-fields.php:235).
			if ( ! current_user_can( 'delete_user_meta', $id, $allowed[ $name ]['storage_key'] ) ) {
				return new WP_Error(
					'rest_cannot_delete_meta',
					/* translators: %s: meta key. */
					sprintf( __( 'You are not allowed to delete the meta key "%s".', 'abilities-catalog' ), $name ),
					array( 'status' => 403 )
				);
			}
		}

		foreach ( $keys as $name ) {
			delete_metadata( 'user', $id, $allowed[ $name ]['storage_key'] );
		}

		return array(
			'id'        => $id,
			'deleted'   => $keys,
			'edit_link' => (string) get_edit_user_link( $id ),
		);
	}
}
