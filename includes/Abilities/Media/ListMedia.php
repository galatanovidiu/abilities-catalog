<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Media;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `media/list-media`.
 *
 * Wraps `GET /wp/v2/media` via `rest_do_request()` and returns the collection
 * plus its total counts. Read-only; REST enforces per-row visibility underneath.
 *
 * @since 0.1.0
 */
final class ListMedia implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'media/list-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'media',
			'label'       => __('Media', 'abilities-catalog'),
			'description' => __('Abilities that read media library items.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Media', 'abilities-catalog'),
			'description'         => __('Lists media library items with optional search, type, parent, author, status, and pagination filters.', 'abilities-catalog'),
			'category'            => 'media',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'     => array(
						'type'        => 'string',
						'description' => __('Limit results to those matching a search term.', 'abilities-catalog'),
					),
					'media_type' => array(
						'type'        => 'string',
						'enum'        => array('image', 'video', 'text', 'application', 'audio'),
						'description' => __('Limit results to a media type.', 'abilities-catalog'),
					),
					'mime_type'  => array(
						'type'        => 'string',
						'description' => __('Limit results to a MIME type (e.g. "image/png").', 'abilities-catalog'),
					),
					'parent'     => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to items attached to the given parent post IDs.', 'abilities-catalog'),
					),
					'author'     => array(
						'type'        => 'array',
						'items'       => array('type' => 'integer'),
						'description' => __('Limit results to the given author user IDs.', 'abilities-catalog'),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __('Limit results to a media status (e.g. "inherit").', 'abilities-catalog'),
					),
					'page'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __('Page of the result set to return.', 'abilities-catalog'),
					),
					'per_page'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __('Number of items to return per page.', 'abilities-catalog'),
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
						'description' => __('The list of media items.', 'abilities-catalog'),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __('Total number of media items matching the query.', 'abilities-catalog'),
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
	 * Permission check: public for view; `edit_posts` for edit-context.
	 *
	 * Media view is public, but the listing requires an authenticated user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may run the query.
	 */
	public function hasPermission($input): bool
	{
		$input   = is_array($input) ? $input : array();
		$context = $input['context'] ?? 'view';

		if ('edit' === $context) {
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

		$request = new WP_REST_Request('GET', '/wp/v2/media');
		$request->set_param('context', $input['context'] ?? 'view');

		if (isset($input['search'])) {
			$request->set_param('search', (string) $input['search']);
		}
		if (isset($input['media_type'])) {
			$request->set_param('media_type', (string) $input['media_type']);
		}
		if (isset($input['mime_type'])) {
			$request->set_param('mime_type', (string) $input['mime_type']);
		}
		if (!empty($input['parent']) && is_array($input['parent'])) {
			$request->set_param('parent', array_map('absint', $input['parent']));
		}
		if (!empty($input['author']) && is_array($input['author'])) {
			$request->set_param('author', array_map('absint', $input['author']));
		}
		if (isset($input['status'])) {
			$request->set_param('status', (string) $input['status']);
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
