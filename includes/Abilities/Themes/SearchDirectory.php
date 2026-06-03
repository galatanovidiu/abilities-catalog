<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Themes;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `themes/search-directory`.
 *
 * Searches the public WordPress.org theme directory via core `themes_api()`
 * (`query_themes`). NOTE: this makes an OUTBOUND HTTP request to the
 * WordPress.org API; it reads remote data and changes nothing on the site.
 * Returns a shaped list (slug, name, version, rating, preview URL, author) so an
 * agent can find a theme slug to then install with the dangerous
 * `themes/install-theme` ability. Read-only.
 *
 * Gated on `install_themes` — the capability needed to act on a result — so the
 * search pairs with the install ability and is not exposed to users who could not
 * install anyway.
 *
 * @since 0.5.0
 */
final class SearchDirectory implements Ability
{
	/**
	 * {@inheritDoc}
	 */
	public function name(): string
	{
		return 'themes/search-directory';
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
			'label'               => __('Search Theme Directory', 'abilities-catalog'),
			'description'         => __('Searches the WordPress.org theme directory by keyword and returns matches (slug, name, version, rating, preview URL, author). This makes an outbound request to the WordPress.org API and changes nothing on the site. Use the returned slug with themes/install-theme to install one.', 'abilities-catalog'),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __('The search keyword(s) to query the theme directory.', 'abilities-catalog'),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __('The page of results to return.', 'abilities-catalog'),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __('The number of results per page (1-100).', 'abilities-catalog'),
					),
				),
				'required'             => array('search'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('items'),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array('slug', 'name'),
							'properties'           => array(
								'slug'        => array(
									'type'        => 'string',
									'description' => __('The theme directory slug (use this to install).', 'abilities-catalog'),
								),
								'name'        => array(
									'type'        => 'string',
									'description' => __('The theme name.', 'abilities-catalog'),
								),
								'version'     => array(
									'type'        => 'string',
									'description' => __('The latest available version.', 'abilities-catalog'),
								),
								'rating'      => array(
									'type'        => 'integer',
									'description' => __('The rating as a percentage (0-100).', 'abilities-catalog'),
								),
								'num_ratings' => array(
									'type'        => 'integer',
									'description' => __('The number of ratings.', 'abilities-catalog'),
								),
								'preview_url' => array(
									'type'        => 'string',
									'description' => __('A URL to preview the theme.', 'abilities-catalog'),
								),
								'author'      => array(
									'type'        => 'string',
									'description' => __('The theme author (plain text).', 'abilities-catalog'),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __('The list of matching themes from the directory.', 'abilities-catalog'),
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
	 * Permission check: `install_themes` (the capability to act on a result).
	 *
	 * The directory search is read-only, but it is gated on the install capability
	 * so it pairs with `themes/install-theme` and is not exposed to users who could
	 * not install anyway. The hard server-side guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may search/install themes.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('install_themes');
	}

	/**
	 * Executes the ability via core `themes_api()` (outbound WordPress.org call).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped matches, or an error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();

		// `themes_api()` is defined in `wp-admin/includes/theme.php` (NOT
		// `theme-install.php`, despite the name); both are admin-only and not
		// loaded over REST. Load theme.php for the function; theme-install.php
		// carries the install-screen helpers. Do not drop 'theme'.
		AdminIncludes::load('theme-install', 'theme');
		if (!function_exists('themes_api')) {
			return new WP_Error(
				'theme_directory_unavailable',
				__('The theme directory API is not available on this site.', 'abilities-catalog'),
				array('status' => 501)
			);
		}

		$result = themes_api(
			'query_themes',
			array(
				'search'   => (string) ($input['search'] ?? ''),
				'page'     => isset($input['page']) ? absint($input['page']) : 1,
				'per_page' => isset($input['per_page']) ? absint($input['per_page']) : 10,
				'fields'   => array(
					'description' => false,
					'sections'    => false,
				),
			)
		);

		if (is_wp_error($result)) {
			return new WP_Error(
				'theme_directory_error',
				__('Could not reach the WordPress.org theme directory.', 'abilities-catalog'),
				array('status' => 502)
			);
		}

		$items = array();
		foreach ((array) ($result->themes ?? array()) as $theme) {
			$theme  = (array) $theme;
			$author = $theme['author'] ?? '';
			if (is_array($author)) {
				$author = $author['display_name'] ?? ($author['user_nicename'] ?? '');
			}

			$items[] = array(
				'slug'        => (string) ($theme['slug'] ?? ''),
				'name'        => wp_strip_all_tags((string) ($theme['name'] ?? '')),
				'version'     => (string) ($theme['version'] ?? ''),
				'rating'      => (int) round((float) ($theme['rating'] ?? 0)),
				'num_ratings' => (int) ($theme['num_ratings'] ?? 0),
				'preview_url' => (string) ($theme['preview_url'] ?? ''),
				'author'      => wp_strip_all_tags((string) $author),
			);
		}

		return array(
			'items' => $items,
		);
	}
}
