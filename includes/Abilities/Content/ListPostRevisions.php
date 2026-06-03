<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Content;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `content/list-post-revisions`.
 *
 * Wraps `GET /wp/v2/posts/<parent>/revisions` via `rest_do_request()`. The
 * capability is object-level `edit_post` on the parent post.
 *
 * @since 0.1.0
 */
final class ListPostRevisions implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/list-post-revisions';
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
			'label'               => __('List Post Revisions', 'abilities-catalog'),
			'description'         => __('Lists the saved revisions of a post by its parent post ID.', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'parent'  => array(
						'type'        => 'integer',
						'description' => __('The parent post ID.', 'abilities-catalog'),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" or "edit".', 'abilities-catalog'),
					),
				),
				'required'             => array('parent'),
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
						'description' => __('The list of revisions.', 'abilities-catalog'),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __('Total number of revisions.', 'abilities-catalog'),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __('Total number of result pages available.', 'abilities-catalog'),
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
	 * Permission check: `edit_post` on the parent post (object-level).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit the parent post.
	 */
	public function hasPermission($input): bool
	{
		$input  = is_array($input) ? $input : array();
		$parent = isset($input['parent']) ? absint($input['parent']) : 0;

		if ($parent <= 0) {
			return false;
		}

		return current_user_can('edit_post', $parent);
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and totals, or the REST error.
	 */
	public function execute($input)
	{
		$input  = is_array($input) ? $input : array();
		$parent = absint($input['parent']);

		$request = new WP_REST_Request('GET', '/wp/v2/posts/' . $parent . '/revisions');
		$request->set_param('context', $input['context'] ?? 'view');

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
