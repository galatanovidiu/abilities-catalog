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
 * T2 write ability: `users/update-meta`.
 *
 * Sets one or more of a user's custom fields (meta). It writes only meta keys the
 * site has registered with `show_in_rest` for users, and rejects any unknown key —
 * it never creates ad-hoc or internal meta. This is a security boundary: raw user
 * meta holds `session_tokens`, `wp_capabilities`, and hashed-key material, none of
 * which is writable here. Wraps core `update_metadata( 'user', ... )` after a
 * per-key `edit_user_meta` capability check; the registered value is sanitized by
 * its `sanitize_callback`. Does not delete meta (use `users/delete-meta`) and does
 * not change other user fields (use `users/update-user`). Returns the user `id`, the
 * applied `meta` values, and `edit_link` (the wp-admin profile editor URL); surface
 * `edit_link` so a human can review the change.
 *
 * The object-level guard runs in `execute()` rather than `permission_callback`: a
 * missing user must surface as a specific `rest_user_invalid_id` (404) instead of a
 * generic permission denial.
 *
 * @since 0.7.0
 */
final class UpdateUserMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/update-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update User Meta', 'abilities-catalog' ),
			'description'         => __( 'Sets custom fields (meta) on a user. Only meta keys registered with show_in_rest for users can be written; unknown keys are rejected, so internal meta such as wp_capabilities or session_tokens cannot be reached. Returns the user id, the applied meta, and edit_link — surface edit_link so a human can review the change. Use users/update-user to change profile fields instead.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The user ID to update meta on. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
					'meta' => array(
						'type'                 => 'object',
						'description'          => __( 'Key/value map of meta to set. Keys must be registered show_in_rest meta for the user.', 'abilities-catalog' ),
						'additionalProperties' => true,
					),
				),
				'required'             => array( 'id', 'meta' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'meta', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The user ID.', 'abilities-catalog' ),
					),
					'meta'      => array(
						'type'        => 'object',
						'description' => __( 'The meta key/value pairs that were applied.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the user. Surface this so a human can review the change. Empty when the user profile is not editable.', 'abilities-catalog' ),
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
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
				'screen'            => 'user-edit.php?user_id={id}',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * This ability calls core directly (no wrapped REST route), so the object-level
	 * `edit_user` capability and the missing-user 404 are enforced in `execute()`.
	 * Returning a non-`true` value here would mask both a missing user and a
	 * permission failure as one generic denial, so the specific
	 * `rest_user_invalid_id` (404) / `rest_cannot_update_meta` (403) errors are
	 * raised from `execute()` instead. The per-key `edit_user_meta` capability is
	 * also enforced there.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by writing registered meta for the user.
	 *
	 * Validates every key up front (registered + per-key capability) and writes
	 * nothing unless all keys pass, so a partial write cannot leave the user in a
	 * surprising state.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The user id, applied meta, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$user  = get_userdata( $id );

		if ( ! $user ) {
			return new WP_Error( 'rest_user_invalid_id', __( 'Invalid user ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this user.', 'abilities-catalog' ), array( 'status' => 403 ) );
		}

		$values = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
		if ( array() === $values ) {
			return new WP_Error( 'rest_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = RegisteredMeta::forObject( 'user', 'user' );

		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error(
					'rest_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for users and cannot be written.', 'abilities-catalog' ), $name ),
					array( 'status' => 400 )
				);
			}

			// The per-key capability is checked against the storage key, matching
			// core (class-wp-rest-meta-fields.php:283, "edit_{$meta_type}_meta").
			if ( ! current_user_can( 'edit_user_meta', $id, $allowed[ $name ]['storage_key'] ) ) {
				return new WP_Error(
					'rest_cannot_update_meta',
					/* translators: %s: meta key. */
					sprintf( __( 'You are not allowed to edit the meta key "%s".', 'abilities-catalog' ), $name ),
					array( 'status' => 403 )
				);
			}
		}

		$applied = array();
		foreach ( $values as $name => $value ) {
			$name        = (string) $name;
			$shape       = $allowed[ $name ];
			$storage_key = $shape['storage_key'];

			if ( $shape['single'] ) {
				// `update_metadata()` returns false both when the new value equals the
				// stored value (a legitimate no-op) and when the write actually fails
				// (a DB error or an `update_user_metadata` filter short-circuit). Detect
				// the no-op up front so only a real failure becomes an error, matching
				// core REST (class-wp-rest-meta-fields.php:382-414).
				$is_noop = $value === get_metadata( 'user', $id, $storage_key, true );
				$result  = update_metadata( 'user', $id, $storage_key, $value );
				if ( false === $result && ! $is_noop ) {
					return $this->databaseError( $name );
				}
			} else {
				// A `single => false` key stores one row per array element.
				// `update_metadata()` would collapse the whole array into a single
				// serialized row, so replace the row set instead: clear the key, then
				// add each value back as its own row (the registered `sanitize_callback`
				// runs inside `add_metadata()`). This matches core REST's multi-value
				// result (class-wp-rest-meta-fields.php::update_multi_meta_value()).
				$new_values = is_array( $value ) ? array_values( $value ) : array( $value );

				delete_metadata( 'user', $id, $storage_key );
				if ( array() !== get_metadata( 'user', $id, $storage_key, false ) ) {
					// The clear was short-circuited (e.g. a `delete_user_metadata`
					// filter); fail rather than append to stale rows and report success.
					return $this->databaseError( $name );
				}

				foreach ( $new_values as $single_value ) {
					if ( false === add_metadata( 'user', $id, $storage_key, $single_value, false ) ) {
						return $this->databaseError( $name );
					}
				}
			}

			$applied[ $name ] = RegisteredMeta::castForResponse( get_metadata( 'user', $id, $storage_key, $shape['single'] ), $shape );
		}

		return array(
			'id'        => $id,
			'meta'      => (object) $applied,
			'edit_link' => (string) get_edit_user_link( $id ),
		);
	}

	/**
	 * Builds the standard 500 database-error response for a meta key.
	 *
	 * @param string $name The public meta key name that failed to write.
	 * @return \WP_Error The database error, carrying the key and a 500 status.
	 */
	private function databaseError( string $name ): WP_Error {
		return new WP_Error(
			'rest_meta_database_error',
			/* translators: %s: meta key. */
			sprintf( __( 'Could not update the meta key "%s".', 'abilities-catalog' ), $name ),
			array(
				'status' => 500,
				'key'    => $name,
			)
		);
	}
}
