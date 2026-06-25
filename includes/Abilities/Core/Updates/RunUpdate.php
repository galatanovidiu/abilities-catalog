<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Updates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use GalatanOvidiu\AbilitiesCatalog\Support\UpgradeRunner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous write ability: `og-updates/run-update`.
 *
 * Runs plugin, theme, or translation updates synchronously through core's bulk
 * upgraders, guarded by the shared {@see UpgradeRunner} lock (filesystem guard +
 * serialized upgrader lock). Core updates are intentionally out of scope: a
 * `type` of `core` (or any value outside the three supported kinds) is rejected
 * with a `WP_Error`, and the permission callback never grants it.
 *
 * Because updating runs the upgraded code's install/activation paths, this
 * ability is annotated destructive and dangerous. It is exposed to the browser
 * only when the adapter's write AND destructive settings are both on, and each
 * call shows an in-page confirmation the human must approve. Capability is the
 * hard guard in every case, mirroring core per type and never weaker.
 *
 * Honest behavior note: the update runs synchronously in a single request. If
 * that request is cut off mid-write, the result is unknown and the agent must
 * re-read update state ({@see ListAvailableUpdates}) to learn what landed. Core
 * upgraders toggle the `.maintenance` flag during the run; on the live path it is
 * cleared on both success and failure, but on a killed worker the only backstop
 * is core's 10-minute `.maintenance` auto-expiry — this ability cannot bound that
 * brief stuck window itself.
 *
 * @since 0.4.0
 */
final class RunUpdate implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-updates/run-update';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Run Update', 'abilities-catalog' ),
			'description'         => __( 'Runs plugin, theme, or translation updates synchronously. Core updates are not supported. Running an update executes the updated code.', 'abilities-catalog' ),
			'category'            => 'og-core-updates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'  => array(
						'type'        => 'string',
						'enum'        => array( 'plugin', 'theme', 'translation' ),
						'description' => __( 'Which kind of update to run: "plugin", "theme", or "translation". Core updates are not supported.', 'abilities-catalog' ),
					),
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
						),
						'description' => __( 'Optional targets. For "plugin", plugin file paths with the .php extension, for example "akismet/akismet.php". For "theme", stylesheet directory names. Ignored for "translation". When omitted, all available updates of the type are run.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'type' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'    => array(
						'type'        => 'string',
						'description' => __( 'The kind of update that ran.', 'abilities-catalog' ),
					),
					'results' => array(
						'type'        => 'object',
						'description' => __( 'Map of each target to true on success or "failed" otherwise. Empty when no updates were available.', 'abilities-catalog' ),
					),
					'message' => array(
						'type'        => 'string',
						'description' => __( 'Optional note, for example when no updates were available.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'type' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'abilities_catalog' => array(
					'scope' => 'global',
				),
				'show_in_rest'      => true,
				'screen'            => 'update-core.php',
			),
		);
	}

	/**
	 * Permission check mirroring core's per-type update capability.
	 *
	 * Maps `plugin` to `update_plugins`, `theme` to `update_themes`, and
	 * `translation` to `update_languages`. Returns false for `core` (out of scope,
	 * never granted), for any other value, and when `type` is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may run the requested update type.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) ? (string) $input['type'] : '';

		switch ( $type ) {
			case 'plugin':
				return current_user_can( 'update_plugins' );
			case 'theme':
				return current_user_can( 'update_themes' );
			case 'translation':
				return current_user_can( 'update_languages' );
			default:
				return false;
		}
	}

	/**
	 * Executes the ability by running the requested updates behind the shared lock.
	 *
	 * Re-validates the type, refreshes the relevant update cache, builds the work
	 * list, and runs the matching bulk upgrader inside {@see UpgradeRunner::withLock()}.
	 * Each result is normalized to a boolean `true` on success or the string
	 * `'failed'` otherwise, so no raw skin output or error message can echo input
	 * back to the agent. A guard error from the runner (filesystem / lock) is
	 * returned unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The normalized result, or a guard/validation error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) ? (string) $input['type'] : '';
		$items = isset( $input['items'] ) && is_array( $input['items'] ) ? array_values( array_filter( array_map( 'strval', $input['items'] ) ) ) : array();

		if ( ! in_array( $type, array( 'plugin', 'theme', 'translation' ), true ) ) {
			return new WP_Error(
				'abilities_catalog_unsupported_update_type',
				__( 'Only plugin, theme, and translation updates are supported. Core updates are not supported by this ability.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		AdminIncludes::load(
			'plugin',
			'update',
			'class-wp-upgrader',
			'class-plugin-upgrader',
			'class-theme-upgrader',
			'class-language-pack-upgrader',
			'class-automatic-upgrader-skin',
			'file'
		);

		if ( 'plugin' === $type ) {
			wp_update_plugins();
			$fs_context = WP_PLUGIN_DIR;
		} elseif ( 'theme' === $type ) {
			wp_update_themes();
			$fs_context = get_theme_root();
		} else {
			// Translation packs live across the core, plugin, and theme update
			// transients (wp-includes/update.php), so refresh all three. Core-bundled
			// language packs are populated only by wp_version_check().
			wp_version_check();
			wp_update_plugins();
			wp_update_themes();
			$fs_context = WP_LANG_DIR;
		}

		return UpgradeRunner::withLock(
			$fs_context,
			function () use ( $type, $items ) {
				if ( 'plugin' === $type ) {
					return $this->runPlugins( $items );
				}

				if ( 'theme' === $type ) {
					return $this->runThemes( $items );
				}

				return $this->runTranslations();
			}
		);
	}

	/**
	 * Runs plugin updates for the given files, or all available when none given.
	 *
	 * @param array<int,string> $items Plugin file paths with the .php extension.
	 * @return array<string,mixed>|\WP_Error The normalized plugin update result, or a top-level failure.
	 */
	private function runPlugins( array $items ) {
		$available = array_keys( get_plugin_updates() );
		// Constrain caller-supplied items to plugins that actually have an available
		// update (the update source is the refreshed transient, never the input).
		// Unknown/uninstalled targets are dropped so they cannot reach the upgrader.
		$plugins = $items ? array_values( array_intersect( $items, $available ) ) : $available;

		if ( empty( $plugins ) ) {
			return array(
				'type'    => 'plugin',
				'results' => (object) array(),
				'message' => $items
					? __( 'None of the requested plugins have an available update.', 'abilities-catalog' )
					: __( 'No plugin updates available.', 'abilities-catalog' ),
			);
		}

		$upgrader = new \Plugin_Upgrader( UpgradeRunner::skin() );
		$result   = $upgrader->bulk_upgrade( $plugins );

		$failure = $this->topLevelFailure( $result );
		if ( null !== $failure ) {
			return $failure;
		}

		return array(
			'type'    => 'plugin',
			'results' => (object) $this->normalizeResults( $result ),
		);
	}

	/**
	 * Runs theme updates for the given stylesheets, or all available when none given.
	 *
	 * @param array<int,string> $items Theme stylesheet directory names.
	 * @return array<string,mixed>|\WP_Error The normalized theme update result, or a top-level failure.
	 */
	private function runThemes( array $items ) {
		$available = array_keys( get_theme_updates() );
		// Constrain caller-supplied items to themes that actually have an available
		// update; drop unknown/uninstalled stylesheets before the upgrader sees them.
		$themes = $items ? array_values( array_intersect( $items, $available ) ) : $available;

		if ( empty( $themes ) ) {
			return array(
				'type'    => 'theme',
				'results' => (object) array(),
				'message' => $items
					? __( 'None of the requested themes have an available update.', 'abilities-catalog' )
					: __( 'No theme updates available.', 'abilities-catalog' ),
			);
		}

		$upgrader = new \Theme_Upgrader( UpgradeRunner::skin() );
		$result   = $upgrader->bulk_upgrade( $themes );

		$failure = $this->topLevelFailure( $result );
		if ( null !== $failure ) {
			return $failure;
		}

		return array(
			'type'    => 'theme',
			'results' => (object) $this->normalizeResults( $result ),
		);
	}

	/**
	 * Runs all available translation (language pack) updates.
	 *
	 * @return array<string,mixed>|\WP_Error The normalized translation update result, or a top-level failure.
	 */
	private function runTranslations() {
		$updates = wp_get_translation_updates();

		if ( empty( $updates ) ) {
			return array(
				'type'    => 'translation',
				'results' => (object) array(),
				'message' => __( 'No translation updates available.', 'abilities-catalog' ),
			);
		}

		$upgrader = new \Language_Pack_Upgrader( UpgradeRunner::skin() );
		$result   = $upgrader->bulk_upgrade( $updates );

		$failure = $this->topLevelFailure( $result );
		if ( null !== $failure ) {
			return $failure;
		}

		return array(
			'type'    => 'translation',
			'results' => (object) $this->normalizeResults( $result ),
		);
	}

	/**
	 * Detects a hard top-level failure from a `bulk_upgrade()` call.
	 *
	 * Core's bulk upgraders can fail before producing any per-target map: they
	 * return a top-level `WP_Error` (for example `Language_Pack_Upgrader` on a
	 * language-dir create failure) or `false` (for example a filesystem-connect
	 * failure in any of the three upgraders). Without this check those collapse to
	 * an empty `results` map, indistinguishable from "nothing to update". A top-level
	 * `WP_Error` is returned unchanged so its code and status survive; a `false`
	 * result becomes a generic `abilities_catalog_update_failed` error. A normal per-target
	 * array yields `null` (no top-level failure).
	 *
	 * @param mixed $result The raw `bulk_upgrade()` return value.
	 * @return \WP_Error|null The top-level failure, or null when the result is a per-target map.
	 */
	private function topLevelFailure( $result ): ?WP_Error {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			return null;
		}

		return new WP_Error(
			'abilities_catalog_update_failed',
			__( 'The update could not be run. The filesystem may be unavailable or the upgrader could not start.', 'abilities-catalog' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Normalizes a bulk-upgrade result to a safe success map.
	 *
	 * `bulk_upgrade()` returns an array keyed by target, with each value the result
	 * (`true`, `false`, a `WP_Error`, or an array of asset data). Each value is
	 * collapsed to a boolean `true` on success or the string `'failed'` otherwise.
	 * Raw skin output and `WP_Error` messages are deliberately dropped so nothing
	 * derived from input is echoed back. A non-array result (overall failure) yields
	 * an empty map.
	 *
	 * @param mixed $result The raw `bulk_upgrade()` return value.
	 * @return array<string,bool|string> Map of target to true or 'failed'.
	 */
	private function normalizeResults( $result ): array {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $result as $item => $outcome ) {
			$success = true === $outcome
				|| ( is_array( $outcome ) && ! empty( $outcome ) );

			$normalized[ (string) $item ] = $success ? true : 'failed';
		}

		return $normalized;
	}
}
