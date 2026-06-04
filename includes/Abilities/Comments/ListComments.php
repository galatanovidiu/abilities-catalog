<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Comments;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `comments/list-comments`.
 *
 * Wraps `GET /wp/v2/comments` via `rest_do_request()` and returns the collection
 * plus its total counts. Read-only; REST enforces per-row visibility underneath.
 * Defaults `context` to `edit` so `author_email` is returned for moderators
 * (`moderate_comments`); REST silently drops edit-only fields for users without
 * the capability.
 *
 * @since 0.1.0
 */
final class ListComments implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'comments/list-comments';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Comments', 'abilities-catalog'),
			'description'         => __('Lists comments with optional post, status, type, author, search, and pagination filters.', 'abilities-catalog'),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post'         => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to comments on the given post IDs.', 'abilities-catalog'),
					),
					'status'       => array(
						'type'        => 'string',
						'description' => __('Limit results to a comment status (e.g. "approve", "hold", "spam", "trash").', 'abilities-catalog'),
					),
					'type'         => array(
						'type'        => 'string',
						'description' => __('Limit results to a comment type (e.g. "comment").', 'abilities-catalog'),
					),
					'author'       => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to comments by the given author user IDs.', 'abilities-catalog'),
					),
					'author_email' => array(
						'type'        => 'string',
						'description' => __('Limit results to comments by a given author email address.', 'abilities-catalog'),
					),
					'search'       => array(
						'type'        => 'string',
						'description' => __('Limit results to those matching a search term.', 'abilities-catalog'),
					),
					'parent'       => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to comments with the given parent comment IDs.', 'abilities-catalog'),
					),
					'page'         => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __('Page of the result set to return.', 'abilities-catalog'),
					),
					'per_page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __('Number of items to return per page.', 'abilities-catalog'),
					),
					'orderby'      => array(
						'type'        => 'string',
						'description' => __('Field to sort by (e.g. "date", "id").', 'abilities-catalog'),
					),
					'order'        => array(
						'type'        => 'string',
						'enum'        => array('asc', 'desc'),
						'description' => __('Sort direction.', 'abilities-catalog'),
					),
					'context'      => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'edit',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (includes author email for moderators).', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('items'),
				'properties'           => array(
					'items'       => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'description' => __('The list of comments.', 'abilities-catalog'),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __('Total number of comments matching the query.', 'abilities-catalog'),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __('Total number of pages available.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: baseline `edit_posts` to list comments.
	 *
	 * Encodes the catalog baseline capability for `comments/list-comments`.
	 * Moderation contexts (non-default status, edit context) need
	 * `moderate_comments`, which REST enforces per row; `edit_posts` is the
	 * minimum required to run the query and is not weaker than that baseline.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may run the query.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		return current_user_can('edit_posts');
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and totals, or the REST error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();

		$request = new WP_REST_Request('GET', '/wp/v2/comments');
		$request->set_param('context', $input['context'] ?? 'edit');

		if (!empty($input['post']) && is_array($input['post'])) {
			$request->set_param('post', array_map('absint', $input['post']));
		}
		if (isset($input['status'])) {
			$request->set_param('status', (string) $input['status']);
		}
		if (isset($input['type'])) {
			$request->set_param('type', (string) $input['type']);
		}
		if (!empty($input['author']) && is_array($input['author'])) {
			$request->set_param('author', array_map('absint', $input['author']));
		}
		if (isset($input['author_email'])) {
			$request->set_param('author_email', sanitize_email((string) $input['author_email']));
		}
		if (isset($input['search'])) {
			$request->set_param('search', (string) $input['search']);
		}
		if (!empty($input['parent']) && is_array($input['parent'])) {
			$request->set_param('parent', array_map('absint', $input['parent']));
		}
		if (isset($input['page'])) {
			$request->set_param('page', absint($input['page']));
		}
		if (isset($input['per_page'])) {
			$request->set_param('per_page', absint($input['per_page']));
		}
		if (isset($input['orderby'])) {
			$request->set_param('orderby', (string) $input['orderby']);
		}
		if (isset($input['order'])) {
			$request->set_param('order', (string) $input['order']);
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$items   = rest_get_server()->response_to_data($response, false);
		$headers = $response->get_headers();

		return array(
			'items'       => is_array($items) ? $items : array(),
			'total'       => (int) ($headers['X-WP-Total'] ?? 0),
			'total_pages' => (int) ($headers['X-WP-TotalPages'] ?? 0),
		);
	}
}
