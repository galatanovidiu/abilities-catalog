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
 * T2 non-destructive write ability: `og-content/restore-post-revision`.
 *
 * Restores a post to a saved revision by wrapping the core function
 * `wp_restore_post_revision()`. This is a net-new write: it does NOT dispatch a
 * REST request, because core exposes no REST route to restore a revision.
 *
 * Restoring is non-destructive: `wp_restore_post_revision()` calls
 * `wp_update_post()`, which itself saves the pre-restore state as a fresh
 * revision before applying the older one. Nothing is permanently lost. Write
 * annotations are therefore `readonly:false, destructive:false, idempotent:false`
 * so the run controller routes the call as POST.
 *
 * That non-destructive guarantee only holds while revisions are enabled. When
 * `wp_revisions_enabled()` is false, `wp_save_post_revision()` bails
 * (revision.php) and no recovery revision is written, so a restore would overwrite
 * the current content with no way back. The ability refuses that exact case —
 * revisions disabled and the target is not an autosave — mirroring core's own
 * wp-admin restore guard (wp-admin/revision.php:52), so every restore it performs
 * stays recoverable and the `destructive:false` classification stays honest.
 *
 * Security note: `wp_restore_post_revision()` performs NO capability check of its
 * own. The `permission_callback` here is the only authorization guard. It mirrors
 * the catalog capability for editing the parent post — object-level
 * `edit_post` on the parent — and additionally rejects any revision whose
 * `post_parent` does not match the supplied parent, so a caller cannot restore a
 * revision onto an unrelated post.
 *
 * @since 0.3.0
 */
final class RestorePostRevision implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/restore-post-revision';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Restore Post Revision', 'abilities-catalog' ),
			'description'         => __( 'Restores a post to a saved revision. The current state is first saved as a new revision, so nothing is lost. Requires the revision to belong to the given parent post.', 'abilities-catalog' ),
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
						'description' => __( 'The revision ID to restore.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'parent', 'revision_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'restored', 'post_id', 'revision_id', 'edit_link' ),
				'properties'           => array(
					'restored'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the revision was restored.', 'abilities-catalog' ),
					),
					'post_id'     => array(
						'type'        => 'integer',
						'description' => __( 'The parent post ID that was restored.', 'abilities-catalog' ),
					),
					'revision_id' => array(
						'type'        => 'integer',
						'description' => __( 'The revision ID that was restored from.', 'abilities-catalog' ),
					),
					'edit_link'   => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the restored post. Surface this so a human can review the result.', 'abilities-catalog' ),
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
	 * Mirrors the sibling write pattern (CreatePost/UpdatePost): a coarse,
	 * object-independent capability guard here, with the object-level `edit_post`
	 * check kept in `execute()`. Core's `wp_restore_post_revision()` does no
	 * capability check, so `execute()` remains the authoritative object-level guard:
	 * it validates both IDs, confirms the target is a revision of the supplied
	 * parent, and requires object-level `edit_post` on the parent — returning a
	 * specific error for each failure (404 for a bad/mismatched revision, 403 for an
	 * authorization failure) instead of masking them as a single permission error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Whether the current user can edit posts; `execute()` enforces the object-level guard.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the restore by wrapping `wp_restore_post_revision()`.
	 *
	 * Re-validates that the revision belongs to the parent (defense in depth, in
	 * case the permission layer is bypassed), then calls the core function. That
	 * function returns the restored parent post ID on success, `false` if there
	 * are no fields to restore (a normal no-op, not an error), or `null` if the
	 * target is not a revision. Distinct failures surface distinct core-mirroring
	 * codes: `rest_post_invalid_id` (404) for a bad/non-revision ID,
	 * `rest_revision_parent_id_mismatch` (404) for a wrong-parent revision,
	 * `rest_revisions_disabled` (409) when revisions are off and the target is not an
	 * autosave (the unrecoverable case core also refuses),
	 * `rest_no_fields_to_restore` (409) for the `false` no-op, and a generic
	 * `rest_restore_failed` (500) for a genuinely unexpected result.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The restore result, or an error if it did not happen.
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

		// Object-level authorization. Core's wp_restore_post_revision() performs no
		// capability check, so this is the only guard against restoring a revision
		// onto a post the current user may not edit.
		if ( ! current_user_can( 'edit_post', $parent ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this post.', 'abilities-catalog' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Data-loss guard. When revisions are disabled, restoring overwrites the
		// current content and wp_save_post_revision() writes no recovery revision, so
		// the operation is unrecoverable despite the destructive:false classification.
		// Refuse the same case core's wp-admin restore refuses (wp-admin/revision.php:52):
		// revisions off AND the target is not an autosave. Autosaves remain restorable,
		// matching core.
		$post = get_post( $parent );
		if ( $post instanceof WP_Post && ! wp_revisions_enabled( $post ) && ! wp_is_post_autosave( $revision ) ) {
			return new WP_Error(
				'rest_revisions_disabled',
				__( 'Revisions are disabled for this post, so restoring would overwrite the current content with no recovery point.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		$result = wp_restore_post_revision( $revision_id );

		// Core returns false when there are no revisionable fields to restore
		// (revision.php:478-480). This is an expected no-op, not a server error.
		if ( false === $result ) {
			return new WP_Error(
				'rest_no_fields_to_restore',
				__( 'No fields were available to restore from this revision.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		if ( ! is_int( $result ) || $result <= 0 ) {
			return new WP_Error(
				'rest_restore_failed',
				__( 'The revision could not be restored.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'restored'    => true,
			'post_id'     => $parent,
			'revision_id' => $revision_id,
			'edit_link'   => (string) get_edit_post_link( $parent, 'raw' ),
		);
	}
}
