<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use GalatanOvidiu\AbilitiesCatalog\Support\FilesystemGuard;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous write ability: `plugins/delete-plugin`.
 *
 * Wraps `DELETE /wp/v2/plugins/<plugin>` via `rest_do_request()`, permanently
 * deleting an installed plugin's files. The plugins controller requires the plugin to
 * be INACTIVE and checks the delete capability; that refusal is surfaced unchanged.
 * Deleting removes code from disk, so this ability is annotated dangerous and is
 * exposed to the browser only behind the third gate plus a per-ability opt-in. Before
 * dispatch it requires a directly writable filesystem ({@see FilesystemGuard}) and
 * refuses to delete a plugin that is a required dependency of another installed
 * plugin. The `plugin` input is the plugin file path without the `.php` extension
 * (for example `hello`); the route is built by concatenation so the slash survives and
 * the controller appends `.php`.
 *
 * @since 0.4.0
 */
final class DeletePlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'plugins/delete-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Plugin', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes an installed, inactive plugin by its file path. Deleting removes the plugin code from disk.', 'abilities-catalog' ),
			'category'            => 'plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path without the .php extension, for example "hello".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'plugin' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the plugin was deleted.', 'abilities-catalog' ),
					),
					'plugin'  => array(
						'type'        => 'string',
						'description' => __( 'The deleted plugin file path without the .php extension.', 'abilities-catalog' ),
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
	 * Permission check mirroring the plugins controller's delete gate.
	 *
	 * Requires the `delete_plugins` capability, matching
	 * `delete_item_permissions_check()`. Returns false when the required `plugin`
	 * input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete plugins.
	 */
	public function hasPermission( $input ): bool {
		$input  = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $plugin ) {
			return false;
		}

		return current_user_can( 'delete_plugins' );
	}

	/**
	 * Executes the ability by guarding dependents and dispatching the REST delete.
	 *
	 * Requires a directly writable filesystem, refuses deletion when the plugin is a
	 * required dependency of another installed plugin, then dispatches
	 * `DELETE /wp/v2/plugins/<plugin>`. Surfaces any REST error unchanged (including the
	 * controller's "plugin is active" refusal).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and plugin path, or an error.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $plugin ) {
			return new WP_Error(
				'webmcp_missing_plugin',
				__( 'A plugin file path is required.', 'abilities-catalog' )
			);
		}

		// Validate the plugin path shape before building the route by concatenation:
		// a single-file slug, or a two-segment "dir/file" path (no extra segments,
		// traversal, or stray characters). Keeps the input contract explicit rather
		// than relying solely on the core route regex to reject malformed values.
		if ( ! preg_match( '#^[a-z0-9-]+(?:/[a-z0-9._-]+)?$#i', $plugin ) ) {
			return new WP_Error(
				'webmcp_invalid_plugin',
				__( 'The plugin path is not a valid plugin file reference.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$fs = FilesystemGuard::ensureDirect( WP_PLUGIN_DIR );
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		AdminIncludes::load( 'plugin', 'class-wp-plugin-dependencies' );

		// Directory slug; '.' for single-file plugins.
		$slug = dirname( plugin_basename( $plugin . '.php' ) );

		if ( class_exists( '\WP_Plugin_Dependencies' ) ) {
			\WP_Plugin_Dependencies::initialize();
			$dependents = \WP_Plugin_Dependencies::get_dependents( $slug );

			if ( ! empty( $dependents ) ) {
				return new WP_Error(
					'webmcp_plugin_has_dependents',
					sprintf(
						/* translators: 1: plugin slug, 2: comma-separated list of dependent plugins. */
						__( 'Cannot delete "%1$s": it is required by %2$s.', 'abilities-catalog' ),
						$slug,
						implode( ', ', (array) $dependents )
					),
					array( 'status' => 409 )
				);
			}
		}

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/plugins/' . $plugin );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		return array(
			'deleted' => true,
			'plugin'  => $plugin,
		);
	}
}
