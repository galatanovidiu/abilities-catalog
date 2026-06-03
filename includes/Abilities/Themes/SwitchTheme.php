<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Themes;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T2 destructive write ability: `themes/switch-theme`.
 *
 * Net-new (no themes REST update route): validates the requested stylesheet with
 * `wp_get_theme()->exists()`, then activates it with `switch_theme()`. Switching the
 * theme changes the whole front end, so this ability is annotated destructive and is
 * exposed to the browser only when the adapter's write AND destructive settings are
 * both on. The existence check always runs first; `switch_theme()` is never called for
 * a missing or broken theme. The outer `/run` call is POST.
 *
 * @since 0.3.0
 */
final class SwitchTheme implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'themes/switch-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'themes',
			'label'       => __('Themes', 'abilities-catalog'),
			'description' => __('Abilities that read installed themes.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Switch Theme', 'abilities-catalog'),
			'description'         => __('Activates an installed theme by its stylesheet (directory name). Switching the theme changes the whole front end.', 'abilities-catalog'),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __('The theme directory name (stylesheet) to activate, for example "twentytwentyfive".', 'abilities-catalog'),
					),
				),
				'required'             => array('stylesheet'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('success', 'active_theme'),
				'properties'           => array(
					'success'      => array(
						'type'        => 'boolean',
						'description' => __('Whether the theme was switched.', 'abilities-catalog'),
					),
					'active_theme' => array(
						'type'        => 'string',
						'description' => __('The active theme stylesheet after the switch.', 'abilities-catalog'),
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
				'screen'       => 'themes.php',
			),
		);
	}

	/**
	 * Permission check: the current user may switch themes.
	 *
	 * Encodes the catalog capability for `themes/switch-theme` (`switch_themes`).
	 * Returns false when the required `stylesheet` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may switch themes.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		if (empty($input['stylesheet'])) {
			return false;
		}

		return current_user_can('switch_themes');
	}

	/**
	 * Executes the ability by validating the theme then switching to it.
	 *
	 * The existence check runs before any mutation: a missing or broken theme
	 * returns a `WP_Error` and `switch_theme()` is never called.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Success flag and the active stylesheet, or an error.
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

		switch_theme($stylesheet);

		return array(
			'success'      => true,
			'active_theme' => (string) get_stylesheet(),
		);
	}
}
