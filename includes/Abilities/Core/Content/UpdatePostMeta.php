<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\PostMetaKeys;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 write ability: `content/update-post-meta`.
 *
 * Sets one or more of a post's custom fields (meta). It writes only meta keys the
 * site has registered with `show_in_rest` for the post type, and rejects any
 * unknown key — it never creates ad-hoc or internal meta. Wraps core
 * `update_post_meta()` after a per-key `edit_post_meta` capability check; the
 * registered value is sanitized by its `sanitize_callback`. Does not delete meta
 * (use `content/delete-post-meta`) and does not change other post fields. Returns
 * the post `id`, the applied `meta` values, and `edit_link` (the wp-admin editor
 * URL); surface `edit_link` so a human can review the change.
 *
 * @since 0.5.0
 */
final class UpdatePostMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/update-post-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Post Meta', 'abilities-catalog' ),
			'description'         => __( 'Sets custom fields (meta) on a post. Only meta keys registered with show_in_rest for the post type can be written; unknown keys are rejected. Returns the post id, the applied meta, and edit_link — surface edit_link so a human can review the change. Use content/list-post-meta-keys to discover writable keys.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to update meta on.', 'abilities-catalog' ),
					),
					'meta' => array(
						'type'                 => 'object',
						'description'          => __( 'Key/value map of meta to set. Keys must be registered show_in_rest meta for the post type.', 'abilities-catalog' ),
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
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'meta'      => array(
						'type'        => 'object',
						'description' => __( 'The meta key/value pairs that were applied.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the post. Surface this so a human can review the change.', 'abilities-catalog' ),
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
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: edit access to the target post (object-level).
	 *
	 * The coarse `edit_post` gate is the hard guard; the per-key
	 * `edit_post_meta` capability is enforced in {@see self::execute()}.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit the post.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Executes the ability by writing registered meta for the post.
	 *
	 * Validates every key up front (registered + per-key capability) and writes
	 * nothing unless all keys pass, so a partial write cannot leave the post in a
	 * surprising state.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post id, applied meta, and edit link, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$post  = get_post( $id );

		if ( ! $post ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		$values = isset( $input['meta'] ) && is_array( $input['meta'] ) ? $input['meta'] : array();
		if ( array() === $values ) {
			return new WP_Error( 'rest_post_meta_empty', __( 'No meta keys provided.', 'abilities-catalog' ), array( 'status' => 400 ) );
		}

		$allowed = PostMetaKeys::forPostType( $post->post_type );

		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $allowed[ $key ] ) ) {
				return new WP_Error(
					'rest_post_meta_unknown_key',
					/* translators: %s: meta key. */
					sprintf( __( 'The meta key "%s" is not registered with show_in_rest for this post type and cannot be written.', 'abilities-catalog' ), $key ),
					array( 'status' => 400 )
				);
			}

			if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) {
				return new WP_Error(
					'rest_cannot_update_post_meta',
					/* translators: %s: meta key. */
					sprintf( __( 'You are not allowed to edit the meta key "%s".', 'abilities-catalog' ), $key ),
					array( 'status' => 403 )
				);
			}
		}

		$applied = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			update_post_meta( $id, $key, $value );
			$applied[ $key ] = get_post_meta( $id, $key, $allowed[ $key ]['single'] );
		}

		return array(
			'id'        => $id,
			'meta'      => (object) $applied,
			'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
		);
	}
}
