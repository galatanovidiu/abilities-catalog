<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Updates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Net-new T1 read ability: `updates/list-available-updates`.
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
		return 'updates/list-available-updates';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		$update_set = array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
			),
		);

		return array(
			'label'               => __( 'List Available Updates', 'abilities-catalog' ),
			'description'         => __( 'Returns the available core, plugin, theme, and translation updates from the cached update data.', 'abilities-catalog' ),
			'category'            => 'updates',
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
					'core'         => $update_set,
					'plugins'      => $update_set,
					'themes'       => $update_set,
					'translations' => $update_set,
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
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
	 * Permission check: any of the core, plugin, or theme update capabilities.
	 *
	 * Encodes the catalog capability for `updates/list-available-updates` — the
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
			$update = isset( $theme->update ) && is_array( $theme->update ) ? $theme->update : array();

			$list[] = array(
				'theme'       => (string) $stylesheet,
				'new_version' => isset( $update['new_version'] ) ? (string) $update['new_version'] : '',
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
