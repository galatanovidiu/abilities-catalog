<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Fonts;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `fonts/delete-font-family`.
 *
 * Wraps `DELETE /wp/v2/font-families/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting an installed `wp_font_family` post and its font-face assets.
 * The `permission_callback` mirrors the font-families controller: the
 * `wp_font_family` post type maps `delete_posts` to `edit_theme_options`, so
 * deleting a family requires `edit_theme_options`. This ability never calls
 * `wp_delete_post()` directly; it surfaces the REST route's `WP_Error` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteFontFamily implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'fonts/delete-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'fonts',
			'label'       => __('Fonts', 'abilities-catalog'),
			'description' => __('Abilities that read installed font families and font collections.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Delete Font Family', 'abilities-catalog'),
			'description'         => __('Permanently deletes an installed font family and its font-face assets by ID. This cannot be undone and may break typography that references it.', 'abilities-catalog'),
			'category'            => 'fonts',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __('The font family post ID to permanently delete.', 'abilities-catalog'),
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
						'description' => __('Whether the font family was permanently deleted.', 'abilities-catalog'),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __('The deleted font family post ID.', 'abilities-catalog'),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the REST controller's delete check.
	 *
	 * The `wp_font_family` post type maps the `delete_posts` capability to
	 * `edit_theme_options`, so deleting a font family requires that capability.
	 * The REST route re-checks it (defense in depth).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete a font family.
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
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=true` so the font family is permanently deleted. Any REST error
	 * is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute($input)
	{
		$input   = is_array($input) ? $input : array();
		$id      = absint($input['id']);
		$request = new WP_REST_Request('DELETE', '/wp/v2/font-families/' . $id);
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
