<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Menus;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `menus/get-classic-menu`.
 *
 * Wraps `GET /wp/v2/menus/<id>` via `rest_do_request()` and shapes the response
 * into a flat field set for a single classic menu (`nav_menu` term). Read-only.
 *
 * @since 0.1.0
 */
final class GetClassicMenu implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'menus/get-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Get Classic Menu', 'abilities-catalog'),
			'description'         => __('Returns a single classic (nav_menu term) menu by ID.', 'abilities-catalog'),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The classic menu term ID.', 'abilities-catalog'),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array('view', 'edit'),
						'default'     => 'view',
						'description' => __('Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'name'),
				'properties'           => array(
					'id'          => array(
						'type'        => 'integer',
						'description' => __('The classic menu term ID.', 'abilities-catalog'),
					),
					'name'        => array(
						'type'        => 'string',
						'description' => __('The menu name.', 'abilities-catalog'),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __('The menu slug.', 'abilities-catalog'),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __('The menu description.', 'abilities-catalog'),
					),
					'count'       => array(
						'type'        => 'integer',
						'description' => __('The number of items in the menu.', 'abilities-catalog'),
					),
					'meta'        => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __('The menu meta, including assigned locations.', 'abilities-catalog'),
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
	 * Permission check: managing menus requires `edit_theme_options`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the classic menu.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error Flat classic menu fields, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request('GET', '/wp/v2/menus/' . $id);
		$request->set_param('context', $context);

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'          => (int) ($data['id'] ?? $id),
			'name'        => (string) ($data['name'] ?? ''),
			'slug'        => (string) ($data['slug'] ?? ''),
			'description' => (string) ($data['description'] ?? ''),
			'count'       => (int) ($data['count'] ?? 0),
			'meta'        => isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : array(),
		);
	}
}
