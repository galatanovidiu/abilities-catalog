<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RegisteredMeta;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 write ability: `og-comments/update-meta`.
 *
 * Sets one or more of a comment's custom fields (meta). It writes only meta keys
 * the site has registered with `show_in_rest` for comments, and rejects any
 * unknown key — it never creates ad-hoc or internal meta. Wraps the core generic
 * `update_metadata()`/`add_metadata()` functions (the same path `update_post_meta`
 * uses for posts) after a per-key `edit_comment_meta` capability check; the
 * registered value is sanitized by its `sanitize_callback`. Does not delete meta
 * (use `og-comments/delete-meta`) and does not change other comment fields. Returns
 * the comment `id`, the applied `meta` values, and `edit_link` (the wp-admin
 * comment editor URL); surface `edit_link` so a human can review the change.
 *
 * @since 0.7.0
 */
final class UpdateCommentMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/update-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Comment Meta', 'abilities-catalog' ),
			'description'         => __( 'Sets custom fields (meta) on a comment. Only meta keys registered with show_in_rest for comments can be written; unknown keys are rejected. Returns the comment id, the applied meta, and edit_link — surface edit_link so a human can review the change. Discover comment IDs with og-comments/list-comments.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to update meta on. Discover IDs with og-comments/list-comments.', 'abilities-catalog' ),
					),
					'meta' => array(
						'type'                 => 'object',
						'description'          => __( 'Key/value map of meta to set. Keys must be registered show_in_rest meta for the comment.', 'abilities-catalog' ),
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
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'meta'      => array(
						'type'        => 'object',
						'description' => __( 'The meta key/value pairs that were applied.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the comment. Surface this so a human can review the change.', 'abilities-catalog' ),
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
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'screen'       => 'comment.php?action=editcomment&c={id}',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * This ability calls core directly (no wrapped REST route), so the object-level
	 * `edit_comment` guard is enforced in `execute()`: a missing comment surfaces
	 * the specific `rest_comment_invalid_id` (404) instead of masking it as a generic
	 * permission denial, and an existing comment the caller cannot edit yields a 403.
	 * The per-key `edit_comment_meta` capability is also enforced in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by writing registered meta for the comment.
	 *
	 * Validates every key up front (registered + per-key capability) and writes
	 * nothing unless all keys pass, so a partial write cannot leave the comment in a
	 * surprising state.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment id, applied meta, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$comment = get_comment( $id );

		if ( ! $comment ) {
			return new WP_Error( 'rest_comment_invalid_id', __( 'Invalid comment ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this comment.', 'abilities-catalog' ), array( 'status' => 403 ) );
		}

		$values = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
		if ( array() === $values ) {
			return new WP_Error( 'rest_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = RegisteredMeta::forObject( 'comment', 'comment' );

		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error(
					'rest_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for comments and cannot be written.', 'abilities-catalog' ), $name ),
					array( 'status' => 400 )
				);
			}

			// The per-key capability is checked against the storage key, matching
			// core (class-wp-rest-meta-fields.php:392).
			if ( ! current_user_can( 'edit_comment_meta', $id, $allowed[ $name ]['storage_key'] ) ) {
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
				// (a DB error or an `update_comment_metadata` filter short-circuit).
				// Detect the no-op up front so only a real failure becomes an error,
				// matching core REST (class-wp-rest-meta-fields.php:382-414).
				$is_noop = $value === get_metadata( 'comment', $id, $storage_key, true );
				$result  = update_metadata( 'comment', $id, $storage_key, $value );
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

				delete_metadata( 'comment', $id, $storage_key );
				if ( array() !== get_metadata( 'comment', $id, $storage_key, false ) ) {
					// The clear was short-circuited (e.g. a `delete_comment_metadata`
					// filter); fail rather than append to stale rows and report success.
					return $this->databaseError( $name );
				}

				foreach ( $new_values as $single_value ) {
					if ( false === add_metadata( 'comment', $id, $storage_key, $single_value, false ) ) {
						return $this->databaseError( $name );
					}
				}
			}

			$applied[ $name ] = RegisteredMeta::castForResponse( get_metadata( 'comment', $id, $storage_key, $shape['single'] ), $shape );
		}

		return array(
			'id'        => $id,
			'meta'      => (object) $applied,
			'edit_link' => (string) get_edit_comment_link( $id ),
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
