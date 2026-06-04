<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\Plugins;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use Automattic\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Read ability: `plugins/search-directory`.
 *
 * Searches the public WordPress.org plugin directory via core `plugins_api()`
 * (`query_plugins`). NOTE: this makes an OUTBOUND HTTP request to the
 * WordPress.org API; it reads remote data and changes nothing on the site.
 * Returns a shaped list (slug, name, version, rating, active installs, short
 * description, author) so an agent can find a plugin slug to then install with
 * the dangerous `plugins/install-plugin` ability. Read-only.
 *
 * Gated on `install_plugins` — the capability needed to act on a result — so the
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
		return 'plugins/search-directory';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Search Plugin Directory', 'abilities-catalog'),
			'description'         => __('Searches the WordPress.org plugin directory by keyword and returns matches (slug, name, version, rating, active installs, short description, author). This makes an outbound request to the WordPress.org API and changes nothing on the site. Use the returned slug with plugins/install-plugin to install one.', 'abilities-catalog'),
			'category'            => 'plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'   => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __('The search keyword(s) to query the plugin directory.', 'abilities-catalog'),
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
								'slug'              => array(
									'type'        => 'string',
									'description' => __('The plugin directory slug (use this to install).', 'abilities-catalog'),
								),
								'name'              => array(
									'type'        => 'string',
									'description' => __('The plugin name.', 'abilities-catalog'),
								),
								'version'           => array(
									'type'        => 'string',
									'description' => __('The latest available version.', 'abilities-catalog'),
								),
								'rating'            => array(
									'type'        => 'integer',
									'description' => __('The rating as a percentage (0-100).', 'abilities-catalog'),
								),
								'num_ratings'       => array(
									'type'        => 'integer',
									'description' => __('The number of ratings.', 'abilities-catalog'),
								),
								'active_installs'   => array(
									'type'        => 'integer',
									'description' => __('The approximate active install count.', 'abilities-catalog'),
								),
								'short_description' => array(
									'type'        => 'string',
									'description' => __('A short plain-text description.', 'abilities-catalog'),
								),
								'author'            => array(
									'type'        => 'string',
									'description' => __('The plugin author (plain text).', 'abilities-catalog'),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __('The list of matching plugins from the directory.', 'abilities-catalog'),
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
	 * Permission check: `install_plugins` (the capability to act on a result).
	 *
	 * The directory search is read-only, but it is gated on the install capability
	 * so it pairs with `plugins/install-plugin` and is not exposed to users who
	 * could not install anyway. The hard server-side guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may search/install plugins.
	 */
	public function hasPermission($input): bool
	{
		return current_user_can('install_plugins');
	}

	/**
	 * Executes the ability via core `plugins_api()` (outbound WordPress.org call).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped matches, or an error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();

		// `plugins_api()` lives in an admin-only include not loaded over REST.
		AdminIncludes::load('plugin-install');
		if (!function_exists('plugins_api')) {
			return new WP_Error(
				'plugin_directory_unavailable',
				__('The plugin directory API is not available on this site.', 'abilities-catalog'),
				array('status' => 501)
			);
		}

		$result = plugins_api(
			'query_plugins',
			array(
				'search'   => (string) ($input['search'] ?? ''),
				'page'     => isset($input['page']) ? absint($input['page']) : 1,
				'per_page' => isset($input['per_page']) ? absint($input['per_page']) : 10,
				'fields'   => array(
					'short_description' => true,
					'icons'             => false,
					'sections'          => false,
				),
			)
		);

		if (is_wp_error($result)) {
			return new WP_Error(
				'plugin_directory_error',
				__('Could not reach the WordPress.org plugin directory.', 'abilities-catalog'),
				array('status' => 502)
			);
		}

		$items = array();
		foreach ((array) ($result->plugins ?? array()) as $plugin) {
			$plugin = (array) $plugin;
			$items[] = array(
				'slug'              => (string) ($plugin['slug'] ?? ''),
				'name'              => wp_strip_all_tags((string) ($plugin['name'] ?? '')),
				'version'           => (string) ($plugin['version'] ?? ''),
				'rating'            => (int) round((float) ($plugin['rating'] ?? 0)),
				'num_ratings'       => (int) ($plugin['num_ratings'] ?? 0),
				'active_installs'   => (int) ($plugin['active_installs'] ?? 0),
				'short_description' => wp_strip_all_tags((string) ($plugin['short_description'] ?? '')),
				'author'            => wp_strip_all_tags((string) ($plugin['author'] ?? '')),
			);
		}

		return array(
			'items' => $items,
		);
	}
}
