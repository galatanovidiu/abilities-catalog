<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Content;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `content/list-post-types`.
 *
 * Wraps `GET /wp/v2/types` via `rest_do_request()`. The REST endpoint returns an
 * object keyed by post-type slug; this ability normalises it into a list of
 * objects so `items` is an array.
 *
 * @since 0.1.0
 */
final class ListPostTypes implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'content/list-post-types';
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
			'label'               => __('List Post Types', 'abilities-catalog'),
			'description'         => __('Lists the REST-enabled post types registered on the site.', 'abilities-catalog'),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'context' => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" or "edit".', 'abilities-catalog'),
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
						'description' => __('The list of post types.', 'abilities-catalog'),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __('Total number of post types returned.', 'abilities-catalog'),
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
	 * Permission check: `edit_posts` for edit-context; otherwise any logged-in user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list post types.
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
	 * The `/wp/v2/types` response is an object keyed by slug; it is converted
	 * into a normalised list of objects.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The normalised list, or the REST error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();

		$request = new WP_REST_Request('GET', '/wp/v2/types');
		$request->set_param('context', $input['context'] ?? 'view');

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		$items = array();
		if (is_array($data)) {
			foreach ($data as $slug => $type) {
				if (!is_array($type)) {
					continue;
				}

				$items[] = array(
					'slug'         => (string) ($type['slug'] ?? $slug),
					'name'         => (string) ($type['name'] ?? ''),
					'hierarchical' => (bool) ($type['hierarchical'] ?? false),
					'rest_base'    => (string) ($type['rest_base'] ?? ''),
					'supports'     => isset($type['supports']) && is_array($type['supports']) ? $type['supports'] : array(),
					'taxonomies'   => isset($type['taxonomies']) && is_array($type['taxonomies']) ? array_values($type['taxonomies']) : array(),
				);
			}
		}

		return array(
			'items'       => $items,
			'total'       => count($items),
			'total_pages' => $items ? 1 : 0,
		);
	}
}
