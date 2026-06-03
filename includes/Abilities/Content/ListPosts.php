<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Content;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `content/list-posts`.
 *
 * Wraps `GET /wp/v2/posts` via `rest_do_request()` and returns the collection
 * plus its total counts. Read-only; REST enforces per-row visibility underneath.
 *
 * @since 0.1.0
 */
final class ListPosts implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/list-posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'content',
			'label'       => __('Content', 'abilities-catalog'),
			'description' => __('Abilities that read posts, pages, and other content.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Posts', 'abilities-catalog'),
			'description'         => __('Lists posts with optional search, status, author, term, and pagination filters.', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'     => array(
						'type'        => 'string',
						'description' => __('Limit results to those matching a search term.', 'abilities-catalog'),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __('Limit results to a post status (e.g. "publish", "draft").', 'abilities-catalog'),
					),
					'author'     => array(
						'type'        => 'integer',
						'description' => __('Limit results to a given author user ID.', 'abilities-catalog'),
					),
					'categories' => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to posts in the given category term IDs.', 'abilities-catalog'),
					),
					'tags'       => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to posts with the given tag term IDs.', 'abilities-catalog'),
					),
					'per_page'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __('Number of items to return per page.', 'abilities-catalog'),
					),
					'page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __('Page of the result set to return.', 'abilities-catalog'),
					),
					'orderby'    => array(
						'type'        => 'string',
						'description' => __('Field to sort by (e.g. "date", "title").', 'abilities-catalog'),
					),
					'order'      => array(
						'type'        => 'string',
						'enum'        => array('asc', 'desc'),
						'description' => __('Sort direction.', 'abilities-catalog'),
					),
					'context'    => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog'),
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
						'description' => __('The list of posts.', 'abilities-catalog'),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __('Total number of posts matching the query.', 'abilities-catalog'),
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
	 * Permission check: public for published posts; `edit_posts` for edit-context
	 * or when a non-public status is requested.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may run the query.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		$context = $input['context'] ?? 'view';
		$status  = isset($input['status']) ? (string) $input['status'] : 'publish';

		if ('edit' === $context || ('publish' !== $status && '' !== $status)) {
			return current_user_can('edit_posts');
		}

		return is_user_logged_in();
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

		$request = new WP_REST_Request('GET', '/wp/v2/posts');
		$request->set_param('context', $input['context'] ?? 'view');

		if (isset($input['search'])) {
			$request->set_param('search', (string) $input['search']);
		}
		if (isset($input['status'])) {
			$request->set_param('status', (string) $input['status']);
		}
		if (isset($input['author'])) {
			$request->set_param('author', absint($input['author']));
		}
		if (!empty($input['categories']) && is_array($input['categories'])) {
			$request->set_param('categories', array_map('absint', $input['categories']));
		}
		if (!empty($input['tags']) && is_array($input['tags'])) {
			$request->set_param('tags', array_map('absint', $input['tags']));
		}
		if (isset($input['per_page'])) {
			$request->set_param('per_page', absint($input['per_page']));
		}
		if (isset($input['page'])) {
			$request->set_param('page', absint($input['page']));
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
