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
 * T2 non-destructive write ability: `menus/create-navigation`.
 *
 * Wraps `POST /wp/v2/navigation` via `rest_do_request()` to create a block-based
 * navigation menu (`wp_navigation` post). The block content lives in `content` as
 * serialized block markup. The `wp_navigation` post type maps every editing
 * capability to `edit_theme_options`, so the permission check mirrors the posts
 * controller's `create_item_permissions_check` against those mapped caps: it
 * requires `edit_theme_options` to create, the same cap when publishing, and the
 * same cap when authoring as another user. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class CreateNavigation implements Ability
{
	/**
	 * Post statuses that require the publish capability.
	 *
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array('publish', 'future', 'private');

	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'menus/create-navigation';
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
			'label'               => __('Create Navigation Menu', 'abilities-catalog'),
			'description'         => __('Creates a new block-based navigation menu. The content is serialized block markup. Defaults to published.', 'abilities-catalog'),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
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
						'default'     => 'publish',
						'description' => __('The navigation menu post status. Defaults to "publish".', 'abilities-catalog'),
					),
				),
				'required'             => array('title'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('id', 'status'),
				'properties'           => array(
					'id'     => array(
						'type'        => 'integer',
						'description' => __('The new navigation menu ID.', 'abilities-catalog'),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __('The resulting navigation menu post status.', 'abilities-catalog'),
					),
					'link'   => array(
						'type'        => 'string',
						'description' => __('The navigation menu permalink.', 'abilities-catalog'),
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
	 * Permission check mirroring the `wp_navigation` posts controller create path.
	 *
	 * The `wp_navigation` post type maps `create_posts`, `publish_posts`, and
	 * `edit_others_posts` all to `edit_theme_options`. Managing navigation menus
	 * therefore requires `edit_theme_options`. The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the navigation menu.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('edit_theme_options');
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The new menu's id, status, link, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$request = new WP_REST_Request('POST', '/wp/v2/navigation');

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
			'id'     => (int) ($data['id'] ?? 0),
			'status' => (string) ($data['status'] ?? ''),
			'link'   => (string) ($data['link'] ?? ''),
		);
	}
}
