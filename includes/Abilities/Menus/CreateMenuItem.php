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
 * T2 non-destructive write ability: `menus/create-menu-item`.
 *
 * Wraps `POST /wp/v2/menu-items` via `rest_do_request()` to create a classic menu
 * item (`nav_menu_item` post). The menu-items controller extends the posts
 * controller; the `nav_menu_item` post type maps `create_posts` / `edit_posts` and
 * `edit_others_posts` to `edit_theme_options`, so the permission check requires
 * `edit_theme_options`. The default item `type` is `custom`, for which the
 * controller requires `title` and `url`. Use `menus` to place the item in a parent
 * menu term. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class CreateMenuItem implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'menus/create-menu-item';
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
			'label'               => __('Create Menu Item', 'abilities-catalog'),
			'description'         => __('Creates a classic menu item. For the default "custom" type, both title and url are required. Set "menus" to the parent menu term ID.', 'abilities-catalog'),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'      => array(
						'type'        => 'string',
						'description' => __('The menu item label. Required for the "custom" type.', 'abilities-catalog'),
					),
					'url'        => array(
						'type'        => 'string',
						'description' => __('The URL the item points to. Required for the "custom" type.', 'abilities-catalog'),
					),
					'type'       => array(
						'type'        => 'string',
						'enum'        => array('taxonomy', 'post_type', 'post_type_archive', 'custom'),
						'default'     => 'custom',
						'description' => __('The family of object the item represents.', 'abilities-catalog'),
					),
					'object'     => array(
						'type'        => 'string',
						'description' => __('The object type, such as "category", "post", or "page".', 'abilities-catalog'),
					),
					'object_id'  => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __('The database ID of the linked object (post ID or term ID).', 'abilities-catalog'),
					),
					'parent'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => __('The menu item ID of the parent item, or 0 for a top-level item.', 'abilities-catalog'),
					),
					'menu_order' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __('The position of the item within the menu.', 'abilities-catalog'),
					),
					'menus'      => array(
						'type'        => 'integer',
						'description' => __('The parent classic menu term ID this item belongs to.', 'abilities-catalog'),
					),
					'status'     => array(
						'type'        => 'string',
						'description' => __('The menu item post status. Defaults to "publish".', 'abilities-catalog'),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The new menu item ID.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting menu item post status.', 'abilities-catalog'),
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
	 * Permission check mirroring the menu-items (posts) controller create path.
	 *
	 * The `nav_menu_item` post type maps `create_posts` / `edit_posts` to
	 * `edit_theme_options`, so managing menu items requires `edit_theme_options`.
	 * The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the menu item.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The new item's id and status, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/menu-items');

		foreach (array('title', 'url', 'type', 'object', 'status') as $field) {
			if (isset($input[$field]) && '' !== $input[$field]) {
				$request->set_param($field, (string) $input[$field]);
			}
		}

		foreach (array('object_id', 'parent', 'menu_order', 'menus') as $field) {
			if (isset($input[$field])) {
				$request->set_param($field, absint($input[$field]));
			}
		}

		$response = rest_do_request($request);
		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'id'     => (int) ($data['id'] ?? 0),
			'status' => (string) ($data['status'] ?? ''),
		);
	}
}
