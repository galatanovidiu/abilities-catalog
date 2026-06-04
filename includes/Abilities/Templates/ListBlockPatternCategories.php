<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Templates;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `templates/list-block-pattern-categories`.
 *
 * Wraps `GET /wp/v2/block-patterns/categories` via `rest_do_request()`. Returns
 * the registered block-pattern categories (the taxonomy that groups block
 * patterns, e.g. "Headers", "Footers", "Call to Action"), each with its name and
 * label. Pairs with `templates/list-patterns` so an agent can group patterns by
 * category. Read-only.
 *
 * @since 0.5.0
 */
final class ListBlockPatternCategories implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'templates/list-block-pattern-categories';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('List Block Pattern Categories', 'abilities-catalog'),
			'description'         => __('Lists the registered block-pattern categories (the groupings used to organize block patterns). Pairs with the list-patterns ability to group patterns by category.', 'abilities-catalog'),
			'category'            => 'templates',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('items'),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'description' => __('The list of registered block-pattern categories.', 'abilities-catalog'),
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
	 * Permission check: `edit_posts` (catalog capability for reading pattern categories).
	 *
	 * Mirrors the block-pattern-categories controller, whose first gate is
	 * `edit_posts`; this guard is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the pattern category registry.
	 */
	public function hasPermission($input = null): bool
	{
		return current_user_can('edit_posts');
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection, or the REST error.
	 */
	public function execute($input = null)
	{
		$request = new WP_REST_Request('GET', '/wp/v2/block-patterns/categories');

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$items = rest_get_server()->response_to_data($response, false);

		return array(
			'items' => is_array($items) ? $items : array(),
		);
	}
}
