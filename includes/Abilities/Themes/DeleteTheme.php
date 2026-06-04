<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Themes;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\AdminIncludes;
use Automattic\AbilitiesCatalog\Support\FilesystemGuard;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T3 dangerous write ability: `themes/delete-theme`.
 *
 * Net-new (no themes REST delete route): permanently deletes an installed theme with
 * core's `delete_theme()`. The theme must exist; the active theme and the parent
 * (template) of the active theme are refused so the site is never left without a usable
 * theme. The filesystem must be directly writable ({@see FilesystemGuard}). Deleting a
 * theme is a permanent removal, so this ability is annotated destructive and dangerous
 * and is exposed to the browser only when the adapter's write AND destructive settings
 * are both on. Capability is the hard guard in all cases. The outer `/run` call is POST.
 *
 * @since 0.5.0
 */
final class DeleteTheme implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'themes/delete-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Delete Theme', 'abilities-catalog'),
			'description'         => __('Permanently deletes an installed theme by its stylesheet (directory name). The active theme and the parent of the active theme cannot be deleted.', 'abilities-catalog'),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __('The theme directory name (stylesheet) to delete, for example "twentytwentyfour".', 'abilities-catalog'),
					),
				),
				'required'             => array('stylesheet'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('deleted', 'stylesheet'),
				'properties'           => array(
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __('Whether the theme was deleted.', 'abilities-catalog'),
					),
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __('The stylesheet (directory name) of the deleted theme.', 'abilities-catalog'),
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
					'dangerous'   => true,
				),
				'show_in_rest' => true,
				'screen'       => 'themes.php',
			),
		);
	}

	/**
	 * Permission check: the current user may delete themes.
	 *
	 * Encodes the catalog capability for `themes/delete-theme` (`delete_themes`).
	 * Returns false when the required `stylesheet` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete themes.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		if (empty($input['stylesheet'])) {
			return false;
		}

		return current_user_can('delete_themes');
	}

	/**
	 * Executes the ability by deleting an installed theme.
	 *
	 * The existence check runs before any mutation, and the active theme and the parent
	 * of the active theme are refused. The filesystem must be directly writable. Any
	 * guard or deletion error is returned as a `WP_Error`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Delete result, or an error.
	 */
	public function execute($input)
	{
		$input      = is_array($input) ? $input : array();
		$stylesheet = isset($input['stylesheet']) ? (string) $input['stylesheet'] : '';

		if ('' === $stylesheet) {
			return new WP_Error(
				'webmcp_missing_stylesheet',
				__('A theme stylesheet is required.', 'abilities-catalog')
			);
		}

		$theme = wp_get_theme($stylesheet);
		if (!$theme->exists()) {
			return new WP_Error(
				'webmcp_theme_not_found',
				/* translators: %s: theme stylesheet. */
				sprintf(__('No installed theme found for stylesheet "%s".', 'abilities-catalog'), $stylesheet),
				array('status' => 404)
			);
		}

		if ($stylesheet === get_stylesheet() || $stylesheet === get_template()) {
			return new WP_Error(
				'webmcp_theme_in_use',
				__('Cannot delete the active theme or the parent of the active theme.', 'abilities-catalog'),
				array('status' => 409)
			);
		}

		$fs = FilesystemGuard::ensureDirect(get_theme_root($stylesheet));
		if (is_wp_error($fs)) {
			return $fs;
		}

		AdminIncludes::load('theme', 'file');

		$result = delete_theme($stylesheet);

		if (is_wp_error($result)) {
			return $result;
		}

		if (false === $result) {
			return new WP_Error(
				'webmcp_delete_failed',
				__('The theme could not be deleted.', 'abilities-catalog'),
				array('status' => 500)
			);
		}

		return array(
			'deleted'    => true,
			'stylesheet' => $stylesheet,
		);
	}
}
