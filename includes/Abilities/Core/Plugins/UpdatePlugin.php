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
 * T3 dangerous write ability: `plugins/update-plugin`.
 *
 * Net-new (not REST-wrapped): updates an installed plugin to the version offered by
 * its configured update source (usually wordpress.org, possibly a third-party Update
 * URI provider) through `Plugin_Upgrader`. Updating replaces plugin code on
 * disk, so this ability is annotated dangerous and is exposed to the browser only
 * behind the third gate plus a per-ability opt-in. The `plugin` input is the plugin
 * file path without the `.php` extension (for example `akismet/akismet`), the same
 * convention as {@see ActivatePlugin}. The upgrader runs inside
 * {@see UpgradeRunner::withLock()}, which requires a directly writable filesystem and
 * serializes concurrent upgrades. The quiet skin's captured output is never returned;
 * a failed run yields a generic error.
 *
 * @since 0.4.0
 */
final class UpdatePlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'plugins/update-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Plugin', 'abilities-catalog' ),
			'description'         => __( 'Updates an installed plugin to the version offered by its configured update source (usually wordpress.org; may be a third-party Update URI provider). Requires direct filesystem write access. Updating replaces the plugin code on disk.', 'abilities-catalog' ),
			'category'            => 'plugins',
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
				'required'             => array( 'plugin', 'version', 'previous_version', 'updated' ),
				'properties'           => array(
					'plugin'           => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path.', 'abilities-catalog' ),
					),
					'version'          => array(
						'type'        => 'string',
						'description' => __( 'The plugin version after the update.', 'abilities-catalog' ),
					),
					'previous_version' => array(
						'type'        => 'string',
						'description' => __( 'The plugin version before the update.', 'abilities-catalog' ),
					),
					'updated'          => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the update completed.', 'abilities-catalog' ),
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
	 * Confirms the plugin is installed, refreshes the update cache, then runs
	 * `Plugin_Upgrader::upgrade()` inside {@see UpgradeRunner::withLock()}. Returns the
	 * guard error if the filesystem is not writable or the lock is held, a 404 if the
	 * plugin is not installed, and a generic 500 if the run does not complete. The
	 * skin's captured output is never surfaced.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The plugin file, new version, and updated flag, or an error.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $plugin ) {
			return new WP_Error(
				'webmcp_missing_plugin',
				__( 'A plugin file path is required.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$file = $plugin . '.php';

		AdminIncludes::load( 'plugin' );

		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return new WP_Error(
				'webmcp_plugin_not_found',
				__( 'The requested plugin is not installed.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$previous_version = isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';

		wp_update_plugins();

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
				'webmcp_update_failed',
				__( 'The plugin update did not complete.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		$installed = get_plugins();
		$version   = isset( $installed[ $file ]['Version'] ) ? (string) $installed[ $file ]['Version'] : '';

		return array(
			'plugin'           => $file,
			'version'          => $version,
			'previous_version' => $previous_version,
			'updated'          => true,
		);
	}
}
