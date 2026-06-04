<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Comments;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `comments/delete-comment`.
 *
 * Wraps `DELETE /wp/v2/comments/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the comment (bypassing the trash). The `permission_callback`
 * mirrors the comments controller `delete_item_permissions_check`, which gates on
 * `check_edit_permission`: `moderate_comments` OR object-level `edit_comment`. This
 * ability never calls `wp_delete_comment()` directly; it surfaces the REST route's
 * `WP_Error` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteComment implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'comments/delete-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Delete Comment', 'abilities-catalog'),
			'description'         => __('Permanently deletes a comment by ID, bypassing the trash. Requires edit permission on the comment. This cannot be undone.', 'abilities-catalog'),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __('The comment ID to permanently delete.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('deleted', 'id'),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __('Whether the comment was permanently deleted.', 'abilities-catalog'),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The deleted comment ID.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit-comments.php',
			),
		);
	}

	/**
	 * Permission check: `moderate_comments` OR object-level `edit_comment`.
	 *
	 * Mirrors the REST comments controller `delete_item_permissions_check`, which
	 * gates on `check_edit_permission`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the comment.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('moderate_comments') || current_user_can('edit_comment', $id);
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete, not trash).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('DELETE', '/wp/v2/comments/' . $id);
		$request->set_param('force', true);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'deleted' => (bool) ($data['deleted'] ?? false),
			'id'      => $id,
		);
	}
}
