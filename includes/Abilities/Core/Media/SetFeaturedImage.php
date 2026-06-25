<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-media/set-featured-image`.
 *
 * Sets a post's featured image (thumbnail) to an existing attachment by wrapping
 * the core function `set_post_thumbnail()`. This is a net-new write: it does NOT
 * dispatch a REST request. The general `og-content/update-post` path can also set a
 * featured image via its `featured_media` field, but verified in core neither
 * `WP_REST_Posts_Controller::handle_featured_media()` nor `set_post_thumbnail()`
 * checks ANY capability on the attachment — only the post's edit cap is enforced.
 *
 * This ability adds the guard that path lacks: a dual object-level `edit_post`
 * check on BOTH the post AND the attachment. That dual guard is the whole reason
 * the ability exists, so it lives in `execute()` (core's function checks nothing).
 *
 * Setting an association is reversible (via `og-media/detach-featured-image`) and
 * re-setting the same image lands the same end state, so write annotations are
 * `readonly:false, destructive:false, idempotent:true`.
 *
 * @since 0.3.0
 */
final class SetFeaturedImage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/set-featured-image';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Set Featured Image', 'abilities-catalog' ),
			'description'         => __( 'Sets a post\'s featured image (thumbnail) to an existing media library attachment. Requires edit access to BOTH the post and the attachment. Reversible via og-media/detach-featured-image.', 'abilities-catalog' ),
			'category'            => 'og-core-media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the post to set the featured image on. Discover IDs with og-content/list-posts or og-content/get-post.', 'abilities-catalog' ),
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The media library attachment ID to use as the featured image. Discover IDs with og-media/list-media.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_id', 'attachment_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'attachment_id', 'set' ),
				'properties'           => array(
					'post_id'       => array(
						'type'        => 'integer',
						'description' => __( 'The post the featured image was set on.', 'abilities-catalog' ),
					),
					'attachment_id' => array(
						'type'        => 'integer',
						'description' => __( 'The attachment now set as the featured image (read back from the post thumbnail).', 'abilities-catalog' ),
					),
					'set'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the featured image was set.', 'abilities-catalog' ),
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
				'screen'       => 'post.php?post={post_id}&action=edit',
			),
		);
	}

	/**
	 * Permission check: coarse `edit_posts` guard.
	 *
	 * Mirrors `og-media/update-media`: a coarse, object-independent capability guard
	 * here, with the object-level checks kept in `execute()`. Core's
	 * `set_post_thumbnail()` performs NO capability check, so `execute()` is the
	 * authoritative object-level guard — it requires `edit_post` on BOTH the post
	 * and the attachment, returning specific errors (`rest_post_invalid_id` 404,
	 * `rest_cannot_edit` 403) instead of one generic permission denial (the
	 * Abilities API swallows a non-`true` return from here into a single error).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Whether the current user can edit posts; `execute()` enforces the object-level guard.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the set by wrapping `set_post_thumbnail()`.
	 *
	 * Validates both IDs, confirms the post exists and the caller can edit it,
	 * confirms the attachment exists, is an `attachment`, and the caller can edit
	 * it (the guard the general `featured_media` path lacks), then sets the
	 * thumbnail. `set_post_thumbnail()` returns a meta ID or `true` on success and
	 * `false` if the attachment is missing or is not a displayable image — that
	 * `false` surfaces as `rest_invalid_featured_media` 400.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The set result, or a specific error.
	 */
	public function execute( $input ) {
		$input         = is_array( $input ) ? $input : array();
		$post_id       = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$attachment_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;

		if ( $post_id <= 0 || $attachment_id <= 0 ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Both post_id and attachment_id must be positive integers.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'The post does not exist.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this post.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'The attachment does not exist or is not a media library item.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Object-level guard on the attachment. The general featured_media path
		// (og-content/update-post) checks no capability on the attachment, so this is
		// the check that ability lacks.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this attachment.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// `set_post_thumbnail()` returns false on an identical re-set (the underlying
		// `update_post_meta()` is a no-op when the value is unchanged). Treat "already
		// set to this attachment" as idempotent success so a repeat call does not
		// surface a spurious 400.
		if ( (int) get_post_thumbnail_id( $post_id ) === $attachment_id ) {
			return array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'set'           => true,
			);
		}

		$result = set_post_thumbnail( $post_id, $attachment_id );
		if ( ! $result ) {
			return new WP_Error(
				'rest_invalid_featured_media',
				__( 'The attachment is not a valid image for a featured image.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'post_id'       => $post_id,
			'attachment_id' => (int) get_post_thumbnail_id( $post_id ),
			'set'           => true,
		);
	}
}
