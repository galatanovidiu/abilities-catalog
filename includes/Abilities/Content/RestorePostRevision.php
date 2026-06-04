<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

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
				'required'             => array( 'restored', 'post_id', 'revision_id' ),
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
	 * Permission check: the only authorization guard for the restore.
	 *
	 * Core's `wp_restore_post_revision()` does no capability check, so this
	 * callback must enforce everything. It validates that both IDs are positive,
	 * confirms the target IS a revision and that its `post_parent` matches the
	 * supplied parent (rejecting a mismatch so a caller cannot restore a revision
	 * onto an unrelated post), and finally requires object-level `edit_post` on
	 * the parent post.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may restore the revision onto the parent.
	 */
	public function hasPermission( $input ): bool {
		$input       = is_array( $input ) ? $input : array();
		$parent      = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		$revision_id = isset( $input['revision_id'] ) ? absint( $input['revision_id'] ) : 0;

		if ( $parent <= 0 || $revision_id <= 0 ) {
			return false;
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( null === $revision || (int) $revision->post_parent !== $parent ) {
			return false;
		}

		return current_user_can( 'edit_post', $parent );
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
		if ( null === $revision || (int) $revision->post_parent !== $parent ) {
			return new WP_Error(
				'webmcp_revision_mismatch',
				__( 'The revision does not exist or does not belong to the given parent post.', 'abilities-catalog' ),
				array( 'status' => 400 )
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
		);
	}
}
