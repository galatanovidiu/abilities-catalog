<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\PostAccess;
use GalatanOvidiu\AbilitiesCatalog\Support\PostMetaKeys;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-content/delete-post-meta`.
 *
 * Removes one or more custom fields (meta) from a post, deleting all stored
 * values for each named key. It operates only on meta keys registered with
 * `show_in_rest` for the post type and rejects unknown keys. Wraps core
 * `delete_post_meta()` after a per-key `delete_post_meta` capability check. This is
 * a data deletion and cannot be undone through this ability; it does not change
 * other post fields. Returns the post `id`, the `deleted` keys, and `edit_link`
 * (the wp-admin editor URL); surface `edit_link` so a human can review the post.
 *
 * @since 0.5.0
 */
final class DeletePostMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/delete-post-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Post Meta', 'abilities-catalog' ),
			'description'         => __( 'Permanently removes custom fields (meta) from a post by key, deleting all values for each key. Only meta keys registered with show_in_rest for the post type can be deleted; unknown keys are rejected. This cannot be undone. Returns the post id, the deleted keys, and edit_link — surface edit_link so a human can review the post.', 'abilities-catalog' ),
			'category'            => 'og-core-content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to delete meta from.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'minItems'    => 1,
						'description' => __( 'The meta keys to remove. Each must be a registered show_in_rest meta key for the post type.', 'abilities-catalog' ),
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
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'deleted'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'The meta keys that were removed.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the post. Surface this so a human can review the post.', 'abilities-catalog' ),
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
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * This ability calls core directly (no wrapped REST route), so object-level
	 * `edit_post` is enforced in `execute()` via
	 * {@see PostAccess::resolveEditable()} — returning `rest_post_invalid_id` (404)
	 * for a missing post and `rest_cannot_edit` (403) when the user may not edit it,
	 * instead of masking both as a single permission error. The per-key
	 * `delete_post_meta` capability is also enforced in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the ability by deleting registered meta from the post.
	 *
	 * Validates every key up front (registered + per-key capability) and deletes
	 * nothing unless all keys pass.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post id, deleted keys, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$post  = PostAccess::resolveEditable( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$keys = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_unique( array_map( 'strval', $input['keys'] ) ) ) : array();
		if ( array() === $keys ) {
			return new WP_Error( 'rest_post_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = PostMetaKeys::forPostType( $post->post_type );

		foreach ( $keys as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				return new WP_Error(
					'rest_post_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for this post type and cannot be deleted.', 'abilities-catalog' ), $name ),
					array( 'status' => 400 )
				);
			}

			// The per-key capability is checked against the storage key, matching
			// core (class-wp-rest-meta-fields.php:238).
			if ( ! current_user_can( 'delete_post_meta', $id, $allowed[ $name ]['storage_key'] ) ) {
				return new WP_Error(
					'rest_cannot_delete_post_meta',
					/* translators: %s: meta key. */
					sprintf( __( 'You are not allowed to delete the meta key "%s".', 'abilities-catalog' ), $name ),
					array( 'status' => 403 )
				);
			}
		}

		foreach ( $keys as $name ) {
			delete_post_meta( $id, $allowed[ $name ]['storage_key'] );
		}

		return array(
			'id'        => $id,
			'deleted'   => $keys,
			'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}
}
