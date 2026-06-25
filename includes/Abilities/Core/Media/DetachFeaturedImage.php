<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-media/detach-featured-image`.
 *
 * Removes a post's featured image (thumbnail) by wrapping the core function
 * `delete_post_thumbnail()`. This is a net-new write: it does NOT dispatch a REST
 * request. Only the post-to-attachment association is removed; the attachment
 * itself is untouched and stays in the media library, so the operation is
 * reversible with `og-media/set-featured-image`. Write annotations are therefore
 * `readonly:false, destructive:false, idempotent:true` (detaching a post that has
 * no featured image is a benign no-op that reaches the same end state).
 *
 * Security note: `delete_post_thumbnail()` performs NO capability check of its own
 * (post.php:8135). The object-level `edit_post` guard in `execute()` is the only
 * authorization check, mirroring the `RestorePostRevision` precedent. The coarse
 * `edit_posts` guard in `permission_callback` is never weaker than core; the
 * object-level decision is repeated in `execute()` so a missing post surfaces a
 * specific 404 and an unauthorized caller a specific 403, instead of one generic
 * permission denial.
 *
 * @since 0.3.0
 */
final class DetachFeaturedImage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/detach-featured-image';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Detach Featured Image', 'abilities-catalog' ),
			'description'         => __( 'Removes a post\'s featured image, clearing the post-to-attachment association only. The attachment itself is not deleted and remains in the media library, so this is reversible with og-media/set-featured-image. Detaching a post that has no featured image is a benign no-op (detached is false). Requires edit access to the post.', 'abilities-catalog' ),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The post ID to remove the featured image from. Discover IDs with og-content/list-posts or og-content/get-post.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'detached' ),
				'properties'           => array(
					'post_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The post ID the featured image was removed from.', 'abilities-catalog' ),
					),
					'detached' => array(
						'type'        => 'boolean',
						'description' => __( 'True if a featured image was actually removed; false if the post had none (a no-op, not an error).', 'abilities-catalog' ),
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
	 * `edit_posts` is the floor every successful editor holds, so requiring it here is
	 * never stricter than core. The object-level `edit_post` decision is repeated in
	 * `execute()` because the wrapped core function `delete_post_thumbnail()` does no
	 * capability check and there is no REST route to surface one — doing the object
	 * check only here would mask a missing post or an authorization failure as a single
	 * generic denial (the Abilities API swallows a non-`true` return).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Whether the current user can edit posts; `execute()` enforces the object-level guard.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the detach by wrapping `delete_post_thumbnail()`.
	 *
	 * Validates the post ID and object-level `edit_post` before mutating, captures
	 * whether a featured image was present (the `detached` signal), then removes the
	 * association. A post with no featured image returns `detached:false`, a normal
	 * no-op rather than an error.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The detach result, or an error if it did not happen.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Object-level authorization. delete_post_thumbnail() checks no capability,
		// so this is the only guard against detaching from a post the caller cannot edit.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this post.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Capture the pre-state: whether a featured image was set. This is the
		// `detached` signal — true means one was actually removed.
		$had = (bool) get_post_thumbnail_id( $post_id );

		delete_post_thumbnail( $post_id );

		return array(
			'post_id'  => $post_id,
			'detached' => $had,
		);
	}
}
