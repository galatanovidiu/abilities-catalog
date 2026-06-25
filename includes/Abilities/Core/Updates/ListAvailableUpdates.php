<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Updates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Net-new T1 read ability: `og-updates/list-available-updates`.
 *
 * Reports the available core, plugin, theme, and translation updates by reading
 * the cached update transients through core's admin-side helper functions. It does
 * not trigger a fresh remote check (`wp_version_check()`, `wp_update_plugins()`,
 * `wp_update_themes()`); empty results are valid when no check has run yet.
 *
 * The `get_core_updates()`, `get_plugin_updates()`, and `get_theme_updates()`
 * helpers live in `wp-admin/includes/update.php`, which is not loaded during REST
 * or front-end requests, so {@see AdminIncludes::load()} requires it before use.
 * `wp_get_translation_updates()` lives in `wp-includes` and needs no load.
 *
 * @since 0.1.0
 */
final class ListAvailableUpdates implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-updates/list-available-updates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		$core_set = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'response' => array(
						'type'        => 'string',
						'description' => __( 'Core update state, e.g. "upgrade", "latest", or "development".', 'abilities-catalog' ),
					),
					'current'  => array(
						'type'        => 'string',
						'description' => __( 'The currently installed core version.', 'abilities-catalog' ),
					),
					'version'  => array(
						'type'        => 'string',
						'description' => __( 'The offered core version.', 'abilities-catalog' ),
					),
					'locale'   => array(
						'type'        => 'string',
						'description' => __( 'The locale of the offered core update.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
		);

		$plugin_set = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin'          => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path relative to the plugins directory.', 'abilities-catalog' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'The human-readable plugin name.', 'abilities-catalog' ),
					),
					'current_version' => array(
						'type'        => 'string',
						'description' => __( 'The currently installed plugin version.', 'abilities-catalog' ),
					),
					'new_version'     => array(
						'type'        => 'string',
						'description' => __( 'The available plugin version.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
		);

		$theme_set = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'theme'           => array(
						'type'        => 'string',
						'description' => __( 'The theme stylesheet (directory) slug.', 'abilities-catalog' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'The human-readable theme name.', 'abilities-catalog' ),
					),
					'current_version' => array(
						'type'        => 'string',
						'description' => __( 'The currently installed theme version.', 'abilities-catalog' ),
					),
					'new_version'     => array(
						'type'        => 'string',
						'description' => __( 'The available theme version.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
		);

		$translation_set = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'     => array(
						'type'        => 'string',
						'description' => __( 'The translation target type: "core", "plugin", or "theme".', 'abilities-catalog' ),
					),
					'slug'     => array(
						'type'        => 'string',
						'description' => __( 'The slug of the core component, plugin, or theme the translation targets.', 'abilities-catalog' ),
					),
					'language' => array(
						'type'        => 'string',
						'description' => __( 'The language code of the available translation pack.', 'abilities-catalog' ),
					),
					'version'  => array(
						'type'        => 'string',
						'description' => __( 'The version of the targeted core component, plugin, or theme — NOT the language-pack version.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
		);

		return array(
			'label'               => __( 'List Available Updates', 'abilities-catalog' ),
			'description'         => __( 'Returns the available core, plugin, theme, and translation updates from the cached update data. This is a read of cached results; it does not trigger a fresh remote check, so an empty result means no cached check has run, not necessarily that no updates exist.', 'abilities-catalog' ),
			'category'            => 'og-core-updates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'core', 'plugins', 'themes', 'translations', 'all' ),
						'default'     => 'all',
						'description' => __( 'Which update set to return: a single type or "all".', 'abilities-catalog' ),
					),
				),
				'required'             => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'core'         => $core_set,
					'plugins'      => $plugin_set,
					'themes'       => $theme_set,
					'translations' => $translation_set,
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: any of the core, plugin, or theme update capabilities.
	 *
	 * Encodes the catalog capability for `og-updates/list-available-updates` — the
	 * union of `update_core`, `update_plugins`, and `update_themes`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read available updates.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'update_core' )
			|| current_user_can( 'update_plugins' )
			|| current_user_can( 'update_themes' );
	}

	/**
	 * Executes the ability by reading the cached update data.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,array<int,array<string,mixed>>> The requested update sets.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) ? (string) $input['type'] : 'all';

		AdminIncludes::load( 'update' );

		$output = array(
			'core'         => array(),
			'plugins'      => array(),
			'themes'       => array(),
			'translations' => array(),
		);

		if ( 'all' === $type || 'core' === $type ) {
			$output['core'] = $this->coreUpdates();
		}

		if ( 'all' === $type || 'plugins' === $type ) {
			$output['plugins'] = $this->pluginUpdates();
		}

		if ( 'all' === $type || 'themes' === $type ) {
			$output['themes'] = $this->themeUpdates();
		}

		if ( 'all' === $type || 'translations' === $type ) {
			$output['translations'] = $this->translationUpdates();
		}

		return $output;
	}

	/**
	 * Maps the available core updates to a flat list.
	 *
	 * @return array<int,array<string,mixed>> Core update entries.
	 */
	private function coreUpdates(): array {
		$updates = function_exists( 'get_core_updates' ) ? get_core_updates() : array();
		if ( ! is_array( $updates ) ) {
			return array();
		}

		$list = array();
		foreach ( $updates as $update ) {
			$list[] = array(
				'response' => isset( $update->response ) ? (string) $update->response : '',
				'current'  => isset( $update->current ) ? (string) $update->current : '',
				'version'  => isset( $update->version ) ? (string) $update->version : '',
				'locale'   => isset( $update->locale ) ? (string) $update->locale : '',
			);
		}

		return $list;
	}

	/**
	 * Maps the available plugin updates to a flat list.
	 *
	 * @return array<int,array<string,mixed>> Plugin update entries.
	 */
	private function pluginUpdates(): array {
		$updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();
		if ( ! is_array( $updates ) ) {
			return array();
		}

		$list = array();
		foreach ( $updates as $plugin_file => $plugin ) {
			$update = $plugin->update ?? null;

			$list[] = array(
				'plugin'          => (string) $plugin_file,
				'name'            => isset( $plugin->Name ) ? (string) $plugin->Name : '',
				'current_version' => isset( $plugin->Version ) ? (string) $plugin->Version : '',
				'new_version'     => isset( $update->new_version ) ? (string) $update->new_version : '',
			);
		}

		return $list;
	}

	/**
	 * Maps the available theme updates to a flat list.
	 *
	 * @return array<int,array<string,mixed>> Theme update entries.
	 */
	private function themeUpdates(): array {
		$updates = function_exists( 'get_theme_updates' ) ? get_theme_updates() : array();
		if ( ! is_array( $updates ) ) {
			return array();
		}

		$list = array();
		foreach ( $updates as $stylesheet => $theme ) {
			// `get_theme_updates()` assigns the update-data array to a dynamic
			// `$theme->update` property, which the WP_Theme stub types as bool
			// (default false). Read it via get_object_vars() to get the real value.
			$vars   = get_object_vars( $theme );
			$update = isset( $vars['update'] ) && is_array( $vars['update'] ) ? $vars['update'] : array();

			$list[] = array(
				'theme'           => (string) $stylesheet,
				'name'            => (string) $theme->get( 'Name' ),
				'current_version' => (string) $theme->get( 'Version' ),
				'new_version'     => isset( $update['new_version'] ) ? (string) $update['new_version'] : '',
			);
		}

		return $list;
	}

	/**
	 * Maps the available translation updates to a flat list.
	 *
	 * @return array<int,array<string,mixed>> Translation update entries.
	 */
	private function translationUpdates(): array {
		$updates = function_exists( 'wp_get_translation_updates' ) ? wp_get_translation_updates() : array();
		if ( ! is_array( $updates ) ) {
			return array();
		}

		$list = array();
		foreach ( $updates as $update ) {
			$list[] = array(
				'type'     => isset( $update->type ) ? (string) $update->type : '',
				'slug'     => isset( $update->slug ) ? (string) $update->slug : '',
				'language' => isset( $update->language ) ? (string) $update->language : '',
				'version'  => isset( $update->version ) ? (string) $update->version : '',
			);
		}

		return $list;
	}
}
