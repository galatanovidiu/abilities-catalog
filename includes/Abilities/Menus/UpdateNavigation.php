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
 * T2 non-destructive write ability: `menus/update-navigation`.
 *
 * Wraps `POST /wp/v2/navigation/<id>` via `rest_do_request()` to update a
 * block-based navigation menu (`wp_navigation` post). The permission check
 * mirrors the posts controller's `update_item_permissions_check`: an object-level
 * `edit_post` check on the navigation post ID, which `map_meta_cap` resolves to
 * `edit_theme_options` for the `wp_navigation` post type. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class UpdateNavigation implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'menus/update-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'menus',
			'label'       => __('Menus', 'abilities-catalog'),
			'description' => __('Abilities that read navigation (block) and classic menus.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Update Navigation Menu', 'abilities-catalog'),
			'description'         => __('Updates an existing block-based navigation menu by ID. Only the supplied fields change.', 'abilities-catalog'),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The navigation menu ID to update.', 'abilities-catalog'),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __('The navigation menu title.', 'abilities-catalog'),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __('The serialized block markup for the menu items.', 'abilities-catalog'),
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array('draft', 'pending', 'private', 'publish', 'future'),
						'description' => __('The navigation menu post status.', 'abilities-catalog'),
					),
				),
				'required'             => array('id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The navigation menu ID.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting navigation menu post status.', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check mirroring the posts controller update path, object-aware.
	 *
	 * Checks `edit_post` on the navigation post ID; `map_meta_cap` resolves it to
	 * `edit_theme_options` for `wp_navigation`. The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the navigation menu.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('edit_post', $id);
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The menu's id and status, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('POST', '/wp/v2/navigation/' . $id);

		foreach (array('title', 'content') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
		}

		if (isset($input['status']) && '' !== $input['status']) {
			$request->set_param('status', sanitize_key((string) $input['status']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'     => (int) ($data['id'] ?? $id),
			'status' => (string) ($data['status'] ?? ''),
		);
	}
}
