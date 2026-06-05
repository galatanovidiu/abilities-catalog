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
 * T2 non-destructive write ability: `content/restore-post-revision`.
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
		return 'content/restore-post-revision';
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
				'screen'       => 'revision.php?revision={revision_id}',
			),
		);
	}

	/**
	 * Permission check: delegated to `execute()`.
	 *
	 * Core's `wp_restore_post_revision()` does no capability check, so `execute()`
	 * is the authorization guard: it validates both IDs, confirms the target is a
	 * revision of the supplied parent, and requires object-level `edit_post` on the
	 * parent — returning a specific error for each failure (a 400 for a bad/mismatched
	 * revision, a 403 for an authorization failure) instead of masking them as a
	 * single permission error. The object-level capability is still enforced
	 * server-side before the restore runs.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
	}

	/**
	 * Executes the restore by wrapping `wp_restore_post_revision()`.
	 *
	 * Re-validates that the revision belongs to the parent (defense in depth, in
	 * case the permission layer is bypassed), then calls the core function. That
	 * function returns the restored parent post ID on success, `false` if there
	 * are no fields to restore, or `null` if the target is not a revision. Any
	 * non-positive result is surfaced as a `WP_Error`.
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
				'webmcp_invalid_input',
				__( 'Both parent and revision_id must be positive integers.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== $parent ) {
			return new WP_Error(
				'webmcp_revision_mismatch',
				__( 'The revision does not exist or does not belong to the given parent post.', 'abilities-catalog' ),
				array( 'status' => 400 )
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

		$result = wp_restore_post_revision( $revision_id );

		if ( ! is_int( $result ) || $result <= 0 ) {
			return new WP_Error(
				'webmcp_restore_failed',
				__( 'The revision could not be restored. No fields were available to restore, or the restore did not complete.', 'abilities-catalog' ),
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
