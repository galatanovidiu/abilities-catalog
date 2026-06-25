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
 * T2 destructive write ability: `og-comments/delete-meta`.
 *
 * Removes one or more custom fields (meta) from a comment, deleting all stored
 * values for each named key. It operates only on meta keys registered with
 * `show_in_rest` for comments and rejects unknown keys — so internal meta such as
 * a comment's moderation-state keys cannot be deleted through this ability. Wraps
 * core `delete_metadata( 'comment', ... )` after a per-key `delete_comment_meta`
 * capability check. This is a data deletion and cannot be undone through this
 * ability; it does not change other comment fields. Returns the comment `id`, the
 * `deleted` keys, and `edit_link` (the wp-admin editor URL); surface `edit_link`
 * so a human can review the comment.
 *
 * @since 0.7.0
 */
final class DeleteCommentMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/delete-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Comment Meta', 'abilities-catalog' ),
			'description'         => __( 'Permanently removes custom fields (meta) from a comment by key, deleting all values for each key. Only meta keys registered with show_in_rest for comments can be deleted; unknown keys are rejected. This cannot be undone. Returns the comment id, the deleted keys, and edit_link — surface edit_link so a human can review the comment.', 'abilities-catalog' ),
			'category'            => 'og-core-comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The comment ID to delete meta from. Discover IDs with og-comments/list-comments.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'minItems'    => 1,
						'description' => __( 'The meta keys to remove. Each must be a registered show_in_rest meta key for the comment.', 'abilities-catalog' ),
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
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'deleted'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The meta keys that were removed.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the comment. Surface this so a human can review the comment.', 'abilities-catalog' ),
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
				'screen'       => 'comment.php?action=editcomment&c={id}',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * This ability calls core directly (no wrapped REST route), so the object-level
	 * `edit_comment` decision and the per-key `delete_comment_meta` capability are
	 * enforced in `execute()`. Doing the object-level check here would mask a missing
	 * comment as a generic permission denial; deferring to `execute()` lets it return
	 * the specific `rest_comment_invalid_id` (404) for a missing comment and
	 * `rest_cannot_delete_meta` (403) when the caller may not delete the key.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by deleting registered meta from the comment.
	 *
	 * Validates every key up front (registered + per-key capability) and deletes
	 * nothing unless all keys pass.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The comment id, deleted keys, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$comment = get_comment( $id );

		if ( ! $comment ) {
			return new WP_Error( 'rest_comment_invalid_id', __( 'Invalid comment ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this comment.', 'abilities-catalog' ), array( 'status' => 403 ) );
		}

		$keys = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_unique( array_map( 'strval', $input['keys'] ) ) ) : array();
		if ( array() === $keys ) {
			return new WP_Error( 'rest_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = RegisteredMeta::forObject( 'comment', 'comment' );

		foreach ( $keys as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error(
					'rest_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for comments and cannot be deleted.', 'abilities-catalog' ), $name ),
					array( 'status' => 400 )
				);
			}

			// The per-key capability is checked against the storage key, matching
			// core (class-wp-rest-meta-fields.php:235).
			if ( ! current_user_can( 'delete_comment_meta', $id, $allowed[ $name ]['storage_key'] ) ) {
				return new WP_Error(
					'rest_cannot_delete_meta',
					/* translators: %s: meta key. */
					sprintf( __( 'You are not allowed to delete the meta key "%s".', 'abilities-catalog' ), $name ),
					array( 'status' => 403 )
				);
			}
		}

		foreach ( $keys as $name ) {
			delete_metadata( 'comment', $id, $allowed[ $name ]['storage_key'] );
		}

		return array(
			'id'        => $id,
			'deleted'   => $keys,
			'edit_link' => (string) get_edit_comment_link( $id ),
		);
	}
}
