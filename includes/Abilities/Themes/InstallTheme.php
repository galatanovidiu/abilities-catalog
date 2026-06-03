<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Themes;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\AdminIncludes;
use Automattic\AbilitiesCatalog\Support\FilesystemGuard;
use Automattic\AbilitiesCatalog\Support\SourceValidator;
use Automattic\AbilitiesCatalog\Support\UpgradeRunner;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * T3 dangerous write ability: `themes/install-theme`.
 *
 * Net-new (no themes REST install route): installs a wordpress.org-directory theme via
 * core's `Theme_Upgrader`. Input is restricted to a clean wp.org directory slug by
 * {@see SourceValidator} — no ZIP URL, remote URL, or local path can reach the upgrader.
 * The filesystem must be directly writable ({@see FilesystemGuard}); the install runs
 * behind the serialized upgrader lock ({@see UpgradeRunner}). Installing code from the
 * directory is dangerous, so this ability is annotated destructive and dangerous and is
 * exposed to the browser only when the adapter's write AND destructive settings are both
 * on. Capability is the hard guard in all cases. The outer `/run` call is POST.
 *
 * @since 0.5.0
 */
final class InstallTheme implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'themes/install-theme';
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
			'label'               => __('Install Theme', 'abilities-catalog'),
			'description'         => __('Installs a theme from the wordpress.org directory by its slug. Installing code from the directory changes the site, so this is a dangerous operation.', 'abilities-catalog'),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'description' => __('The wordpress.org theme directory slug to install, for example "twentytwentyfive".', 'abilities-catalog'),
					),
				),
				'required'             => array('slug'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('installed', 'stylesheet', 'name'),
				'properties'           => array(
					'installed'  => array(
						'type'        => 'boolean',
						'description' => __('Whether the theme was installed.', 'abilities-catalog'),
					),
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __('The stylesheet (directory name) of the installed theme.', 'abilities-catalog'),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __('The display name of the installed theme.', 'abilities-catalog'),
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
	 * Permission check: the current user may install themes.
	 *
	 * Encodes the catalog capability for `themes/install-theme` (`install_themes`).
	 * Returns false when the required `slug` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may install themes.
	 */
	public function hasPermission($input): bool
	{
		$input = is_array($input) ? $input : array();

		if (empty($input['slug'])) {
			return false;
		}

		return current_user_can('install_themes');
	}

	/**
	 * Executes the ability by installing a wordpress.org-directory theme.
	 *
	 * The slug is validated first, then the filesystem must be directly writable. The
	 * download link is resolved from the wordpress.org themes API; the install runs
	 * behind the serialized upgrader lock. Any guard or upgrader error is returned as a
	 * `WP_Error`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Install result, or an error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();
		$slug  = isset($input['slug']) ? (string) $input['slug'] : '';

		$slug = SourceValidator::slug($slug);
		if (is_wp_error($slug)) {
			return $slug;
		}

		$fs = FilesystemGuard::ensureDirect(get_theme_root());
		if (is_wp_error($fs)) {
			return $fs;
		}

		AdminIncludes::load('theme', 'class-wp-upgrader', 'class-theme-upgrader', 'file');

		$api = themes_api('theme_information', array('slug' => $slug, 'fields' => array('sections' => false)));
		if (is_wp_error($api)) {
			return $api;
		}

		if (empty($api->download_link)) {
			return new WP_Error(
				'webmcp_theme_not_found',
				__('No wordpress.org theme found for that slug.', 'abilities-catalog'),
				array('status' => 404)
			);
		}

		$result = UpgradeRunner::withLock(get_theme_root(), function () use ($api) {
			$upgrader = new \Theme_Upgrader(UpgradeRunner::skin());

			return $upgrader->install($api->download_link);
		});

		if (is_wp_error($result)) {
			return $result;
		}

		if (true !== $result) {
			return new WP_Error(
				'webmcp_install_failed',
				__('The theme installation did not complete.', 'abilities-catalog'),
				array('status' => 500)
			);
		}

		return array(
			'installed'  => true,
			'stylesheet' => $slug,
			'name'       => (string) $api->name,
		);
	}
}
