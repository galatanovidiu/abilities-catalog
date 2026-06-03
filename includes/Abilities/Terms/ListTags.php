<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Terms;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `terms/list-tags`.
 *
 * Wraps `GET /wp/v2/tags` via `rest_do_request()` and returns the matching
 * post-tag terms with pagination totals taken from the REST response headers.
 *
 * @since 0.1.0
 */
final class ListTags implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'terms/list-tags';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'terms',
			'label'       => __('Terms', 'abilities-catalog'),
			'description' => __('Abilities that read taxonomy terms (categories, tags, and custom taxonomies).', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Tags', 'abilities-catalog'),
			'description'         => __('Returns post-tag terms, optionally filtered and paginated.', 'abilities-catalog'),
			'category'            => 'terms',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'description' => __('Limit results to terms matching a search string.', 'abilities-catalog'),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => __('Number of terms to return per page.', 'abilities-catalog'),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('Page number of the result set.', 'abilities-catalog'),
					),
					'orderby'  => array(
						'type'        => 'string',
						'enum'        => array('id', 'name', 'slug', 'count', 'term_group', 'include', 'description'),
						'description' => __('Field to sort the terms by.', 'abilities-catalog'),
					),
					'order'    => array(
						'type'        => 'string',
						'enum'        => array('asc', 'desc'),
						'description' => __('Sort direction.', 'abilities-catalog'),
					),
					'context'  => array(
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
						'description' => __('The matching post-tag terms.', 'abilities-catalog'),
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __('Total number of matching terms.', 'abilities-catalog'),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __('Total number of result pages.', 'abilities-catalog'),
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
	 * Permission check: term reads require an authenticated user.
	 *
	 * Edit-context additionally requires `manage_post_tags`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list tags.
	 */
	public function hasPermission($input): bool
	{
		$input   = is_array($input) ? $input : array();
		$context = $input['context'] ?? 'view';

		if ('edit' === $context) {
			return current_user_can('manage_post_tags');
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error List of terms with totals, or the REST error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();

		$request = new WP_REST_Request('GET', '/wp/v2/tags');
		$request->set_param('context', $input['context'] ?? 'view');
		foreach (array('search', 'per_page', 'page', 'orderby', 'order') as $param) {
			if (isset($input[$param])) {
				$request->set_param($param, $input[$param]);
			}
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data    = rest_get_server()->response_to_data($response, false);
		$headers = $response->get_headers();

		return array(
			'items'       => is_array($data) ? array_values($data) : array(),
			'total'       => (int) ($headers['X-WP-Total'] ?? 0),
			'total_pages' => (int) ($headers['X-WP-TotalPages'] ?? 0),
		);
	}
}
