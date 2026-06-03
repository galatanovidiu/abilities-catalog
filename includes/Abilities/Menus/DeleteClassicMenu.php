<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Menus;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `menus/delete-classic-menu`.
 *
 * Wraps `DELETE /wp/v2/menus/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting a whole classic menu (a `nav_menu` term) and all of its
 * menu items. Classic menus have no Trash: the menus controller returns HTTP 501
 * when `force` is false, so a permanent delete is the only option. This deletes
 * the entire menu, not a single item — use `menus/delete-menu-item` for one item.
 *
 * The `permission_callback` mirrors the terms controller
 * `delete_item_permissions_check`: object-level `delete_term` on the menu term id,
 * which `map_meta_cap` resolves to `edit_theme_options` for `nav_menu`. This
 * ability never calls `wp_delete_nav_menu()` directly; it surfaces the REST
 * route's `WP_Error` unchanged. Destructive: exposed to the browser only when both
 * the write and destructive adapter settings are on. Capability is the hard guard.
 *
 * @since 0.5.0
 */
final class DeleteClassicMenu implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'menus/delete-classic-menu';
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
			'label'               => __('Delete Classic Menu', 'abilities-catalog'),
			'description'         => __('Permanently deletes an entire classic menu (a nav_menu term) and all of its items by menu ID. Classic menus have no Trash, so this cannot be undone. Deletes the whole menu, not a single item.', 'abilities-catalog'),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __('The classic menu (nav_menu term) ID to permanently delete.', 'abilities-catalog'),
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
						'description' => __('Whether the menu was permanently deleted.', 'abilities-catalog'),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The deleted menu ID.', 'abilities-catalog'),
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
				'screen'       => 'nav-menus.php',
			),
		);
	}

	/**
	 * Permission check: object-level `delete_term` on the target menu.
	 *
	 * Mirrors the menus (terms) REST controller `delete_item_permissions_check`;
	 * `map_meta_cap` resolves `delete_term` to `edit_theme_options` for `nav_menu`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete the menu.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();
		$id    = isset($input['id']) ? absint($input['id']) : 0;

		if ($id <= 0) {
			return false;
		}

		return current_user_can('delete_term', $id);
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=true` so the menu is permanently deleted (classic menus have
	 * no Trash). Any REST error is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('DELETE', '/wp/v2/menus/' . $id);
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
