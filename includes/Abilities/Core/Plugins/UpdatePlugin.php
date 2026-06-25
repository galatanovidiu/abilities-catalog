<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use GalatanOvidiu\AbilitiesCatalog\Support\UpgradeRunner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous write ability: `og-plugins/update-plugin`.
 *
 * Net-new (not REST-wrapped): updates an installed plugin to the version offered by
 * its configured update source (usually wordpress.org, possibly a third-party Update
 * URI provider) through `Plugin_Upgrader`. Updating replaces plugin code on
 * disk, so this ability is annotated dangerous and is exposed to the browser only
 * behind the third gate plus a per-ability opt-in. The `plugin` input is the plugin
 * file path without the `.php` extension (for example `akismet/akismet`), the same
 * convention as {@see ActivatePlugin}, and the returned `plugin` field uses the same
 * suffix-free form so it round-trips into the sibling abilities. The upgrader runs
 * inside {@see UpgradeRunner::withLock()}, which requires a directly writable
 * filesystem and serializes concurrent upgrades. The quiet skin's captured output is
 * never returned; a failed run yields a generic error.
 *
 * Two runtime behaviors are handled explicitly so the result does not lie:
 * - When no update is offered the ability returns a stable no-op (`updated: false`,
 *   `version` unchanged) instead of mapping the upgrader's `up_to_date` false return
 *   to a generic failure.
 * - Core silently deactivates an active (incl. network-active) plugin before the
 *   upgrade and never re-enables it. The ability captures the prior active state and
 *   reactivates the plugin afterwards (preserving network scope), reporting the
 *   outcome in `reactivated`, so an update does not leave a running plugin disabled.
 *
 * @since 0.4.0
 */
final class UpdatePlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-plugins/update-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Plugin', 'abilities-catalog' ),
			'description'         => __( 'Updates an installed plugin to the version offered by its configured update source (usually wordpress.org; may be a third-party Update URI provider). Requires direct filesystem write access. Updating replaces the plugin code on disk.', 'abilities-catalog' ),
			'category'            => 'og-core-plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path without the .php extension, for example "akismet/akismet".', 'abilities-catalog' ),
						'minLength'   => 1,
						'pattern'     => '^[^./]+(?:/[^./]+)?$',
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'plugin', 'version', 'previous_version', 'updated', 'reactivated' ),
				'properties'           => array(
					'plugin'           => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path without the .php extension, matching the input form.', 'abilities-catalog' ),
					),
					'version'          => array(
						'type'        => 'string',
						'description' => __( 'The plugin version after the update (unchanged when no update was offered).', 'abilities-catalog' ),
					),
					'previous_version' => array(
						'type'        => 'string',
						'description' => __( 'The plugin version before the update.', 'abilities-catalog' ),
					),
					'updated'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether an update was applied. False when the plugin was already up to date.', 'abilities-catalog' ),
					),
					'reactivated'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the plugin was reactivated after the update. True only when it was active before and is active after; false when it was not active or no update ran.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
				'screen'       => 'plugins.php',
			),
		);
	}

	/**
	 * Permission check: the current user may update plugins.
	 *
	 * Requires the `update_plugins` capability. Returns false when the required
	 * `plugin` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update plugins.
	 */
	public function hasPermission( $input ): bool {
		$input  = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $plugin ) {
			return false;
		}

		return current_user_can( 'update_plugins' );
	}

	/**
	 * Executes the ability by running the plugin upgrader behind the shared lock.
	 *
	 * Confirms the plugin is installed and refreshes the update cache. When the
	 * `update_plugins` transient offers no update the run is a stable no-op
	 * (`updated: false`) rather than a failure. Otherwise it captures the plugin's
	 * active state, runs `Plugin_Upgrader::upgrade()` inside
	 * {@see UpgradeRunner::withLock()}, and reactivates the plugin if core silently
	 * deactivated it. Returns the guard error if the filesystem is not writable or the
	 * lock is held, a 404 if the plugin is not installed, and a generic 500 if the run
	 * does not complete. The skin's captured output is never surfaced.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The plugin file, versions, and updated/reactivated flags, or an error.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $plugin ) {
			return new WP_Error(
				'abilities_catalog_missing_plugin',
				__( 'A plugin file path is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$file = $plugin . '.php';

		AdminIncludes::load( 'plugin' );

		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return new WP_Error(
				'abilities_catalog_plugin_not_found',
				__( 'The requested plugin is not installed.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$previous_version = isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';

		// Refresh the update cache, then preflight the offer. Core's
		// Plugin_Upgrader::upgrade() short-circuits to a falsy `up_to_date` return when
		// the plugin has no entry in the transient response (class-plugin-upgrader.php:200-206),
		// which the old code mapped to a bogus 500. Detect the no-op explicitly instead.
		wp_update_plugins();

		$updates    = get_site_transient( 'update_plugins' );
		$has_update = is_object( $updates ) && ! empty( $updates->response ) && isset( $updates->response[ $file ] );

		if ( ! $has_update ) {
			return array(
				'plugin'           => $plugin,
				'version'          => $previous_version,
				'previous_version' => $previous_version,
				'updated'          => false,
				'reactivated'      => false,
			);
		}

		// On the live request path core silently deactivates an active plugin before
		// upgrading (deactivate_plugin_before_upgrade -> deactivate_plugins($plugin, true),
		// class-plugin-upgrader.php:580) and the Automatic_Upgrader_Skin never re-enables
		// it. Capture the prior state, including network scope, to restore afterwards.
		$was_active         = is_plugin_active( $file );
		$was_network_active = is_plugin_active_for_network( $file );

		AdminIncludes::load( 'class-wp-upgrader', 'class-plugin-upgrader' );

		$result = UpgradeRunner::withLock(
			WP_PLUGIN_DIR,
			static function () use ( $file ) {
				$upgrader = new \Plugin_Upgrader( UpgradeRunner::skin() );

				return $upgrader->upgrade( $file );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result || null === $result ) {
			return new WP_Error(
				'abilities_catalog_update_failed',
				__( 'The plugin update did not complete.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		$installed = get_plugins();
		$version   = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';

		// Restore the plugin's active state if core deactivated it for the upgrade.
		// Reactivate silently (no activation hooks) to mirror the silent deactivation
		// and preserve the original network scope.
		$reactivated = false;
		if ( $was_active && ! is_plugin_active( $file ) ) {
			$reactivation = activate_plugin( $file, '', $was_network_active, true );

			if ( is_wp_error( $reactivation ) ) {
				return new WP_Error(
					'abilities_catalog_plugin_reactivation_failed',
					sprintf(
						/* translators: 1: plugin version, 2: underlying error message. */
						__( 'The plugin updated to version %1$s but could not be reactivated: %2$s', 'abilities-catalog' ),
						$version,
						$reactivation->get_error_message()
					),
					array(
						'status'           => 500,
						'plugin'           => $plugin,
						'version'          => $version,
						'previous_version' => $previous_version,
					)
				);
			}

			$reactivated = is_plugin_active( $file );
		}

		return array(
			'plugin'           => $plugin,
			'version'          => $version,
			'previous_version' => $previous_version,
			'updated'          => true,
			'reactivated'      => $reactivated,
		);
	}
}
