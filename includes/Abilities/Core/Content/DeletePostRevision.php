<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive write ability: `og-content/delete-post-revision`.
 *
 * Permanently deletes one saved revision of a post by wrapping the core function
 * `wp_delete_post_revision()`. This is a net-new write: it does NOT dispatch a
 * REST request, because core exposes no REST route to delete a single revision.
 *
 * Deleting a revision removes only that historical snapshot — the post's current
 * content is unaffected — but the snapshot itself is gone for good. Unlike
 * `og-content/restore-post-revision` (which saves the pre-restore state as a fresh
 * revision and is therefore `destructive:false`), this operation writes no
 * recovery point and cannot be undone, so it is `destructive:true`.
 *
 * Security note: `wp_delete_post_revision()` performs NO capability check of its
 * own (revision.php:617 — it delegates to `wp_delete_post()`). The
 * `permission_callback` here is the only authorization guard. It mirrors the
 * catalog capability for editing the parent post — object-level `edit_post` on
 * the parent — and additionally rejects any revision whose `post_parent` does
 * not match the supplied parent, so a caller cannot delete a revision belonging
 * to an unrelated post.
 *
 * @since 0.3.0
 */
final class DeletePostRevision implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/delete-post-revision';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Post Revision', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes one saved revision of a post. The post\'s current content is unaffected; only that historical snapshot is removed. This cannot be undone. The revision must belong to the given parent post. To roll the post back to an older revision instead of deleting one, use og-content/restore-post-revision.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent'      => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID that the revision belongs to.', 'abilities-catalog' ),
					),
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID to delete. Discover IDs with og-content/list-post-revisions.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'parent', 'revision_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'post_id', 'revision_id' ),
				'properties'           => array(
					'deleted'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the revision was permanently deleted. Always true on success.', 'abilities-catalog' ),
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID whose revision was deleted. The post\'s current content is unchanged.', 'abilities-catalog' ),
					),
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID that was deleted. It no longer exists.', 'abilities-catalog' ),
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
				'screen'       => 'post.php?post={parent}&action=edit',
			),
		);
	}

	/**
	 * Permission check: coarse `edit_posts` guard.
	 *
	 * Mirrors the sibling write pattern (RestorePostRevision): a coarse,
	 * object-independent capability guard here, with the object-level `edit_post`
	 * check kept in `execute()`. Core's `wp_delete_post_revision()` does no
	 * capability check, so `execute()` remains the authoritative object-level
	 * guard: it validates both IDs, confirms the target is a revision of the
	 * supplied parent, and requires object-level `edit_post` on the parent —
	 * returning a specific error for each failure (404 for a bad/mismatched
	 * revision, 403 for an authorization failure) instead of masking them as a
	 * single permission error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Whether the current user can edit posts; `execute()` enforces the object-level guard.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the delete by wrapping `wp_delete_post_revision()`.
	 *
	 * Re-validates that the revision belongs to the parent (defense in depth, in
	 * case the permission layer is bypassed) and enforces object-level `edit_post`
	 * BEFORE calling the core function, which checks no capability of its own.
	 * Distinct failures surface distinct core-mirroring codes:
	 * `rest_post_invalid_id` (404) for a bad/non-revision ID,
	 * `rest_revision_parent_id_mismatch` (404) for a wrong-parent revision,
	 * `rest_cannot_edit` (403) for an authorization failure, and
	 * `rest_revision_delete_failed` (500) when the core delete returns a falsy
	 * value or a `WP_Error`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The delete result, or an error if it did not happen.
	 */
	public function execute( $input ) {
		$input       = is_array( $input ) ? $input : array();
		$parent      = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;

		if ( $parent <= 0 || $revision_id <= 0 ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Both parent and revision_id must be positive integers.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision instanceof WP_Post ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'The revision does not exist or is not a revision.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $revision->post_parent !== $parent ) {
			return new WP_Error(
				'rest_revision_parent_id_mismatch',
				__( 'The revision does not belong to the given parent post.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Object-level authorization. Core's wp_delete_post_revision() performs no
		// capability check, so this is the only guard against deleting a revision
		// of a post the current user may not edit.
		if ( ! current_user_can( 'edit_post', $parent ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this post.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$deleted = wp_delete_post_revision( $revision_id );

		// Core returns the deleted WP_Post on success, or false/null/WP_Error
		// otherwise (revision.php:617). Treat anything falsy or an error as a
		// failure.
		if ( ! $deleted || is_wp_error( $deleted ) ) {
			return new WP_Error(
				'rest_revision_delete_failed',
				__( 'The revision could not be deleted.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'deleted'     => true,
			'post_id'     => $parent,
			'revision_id' => $revision_id,
		);
	}
}
