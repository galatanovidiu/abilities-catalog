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
 * T2 non-destructive write ability: `menus/create-classic-menu`.
 *
 * Wraps `POST /wp/v2/menus` via `rest_do_request()` to create a classic menu
 * (`nav_menu` term). The menus controller inherits its create permission from the
 * terms controller, which checks the taxonomy's `edit_terms` capability; for the
 * `nav_menu` taxonomy every term capability maps to `edit_theme_options`. The
 * permission check therefore requires `edit_theme_options`. Optionally assigns
 * theme locations via the controller's `locations` write field. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class CreateClassicMenu implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'menus/create-classic-menu';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Create Classic Menu', 'abilities-catalog'),
			'description'         => __('Creates a new classic (nav_menu term) menu. Optionally assigns it to theme locations.', 'abilities-catalog'),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __('The menu name.', 'abilities-catalog'),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __('The menu description.', 'abilities-catalog'),
					),
					'locations'   => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => __('Theme location slugs to assign this menu to.', 'abilities-catalog'),
					),
				),
				'required'             => array('name'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'name'),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __('The new classic menu term ID.', 'abilities-catalog'),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __('The resulting menu name.', 'abilities-catalog'),
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
				'screen'       => 'nav-menus.php',
			),
		);
	}

	/**
	 * Permission check mirroring the terms controller create path for `nav_menu`.
	 *
	 * The terms controller checks the taxonomy's `edit_terms` capability on create;
	 * for `nav_menu` it maps to `edit_theme_options`. The REST route re-checks.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the classic menu.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The new menu's id and name, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/menus');

		foreach (array('name', 'description') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
		}

		if (!empty($input['locations']) && is_array($input['locations'])) {
			$request->set_param('locations', array_map('sanitize_key', $input['locations']));
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'   => (int) ($data['id'] ?? 0),
			'name' => (string) ($data['name'] ?? ''),
		);
	}
}
