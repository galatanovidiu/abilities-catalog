<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `plugins/deactivate-plugin`.
 *
 * Wraps `POST /wp/v2/plugins/<plugin>` with `status=inactive` via `rest_do_request()`,
 * deactivating an active plugin. Deactivating changes which code runs on the site, so
 * this ability is annotated destructive and is exposed to the browser only when the
 * adapter's write AND destructive settings are both on. The `plugin` input is the
 * plugin file path without the `.php` extension (for example `akismet/akismet`); the
 * route is built by concatenation so the slash is preserved and the plugins
 * controller's `sanitize_plugin_param()` appends `.php`. The outer `/run` call is POST
 * (status update, not a delete) and the internal REST request is POST too.
 *
 * @since 0.3.0
 */
final class DeactivatePlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'plugins/deactivate-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Deactivate Plugin', 'abilities-catalog' ),
			'description'         => __( 'Deactivates an active plugin by its file path.', 'abilities-catalog' ),
			'category'            => 'plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path without the .php extension, for example "akismet/akismet".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'plugin', 'status' ),
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path.', 'abilities-catalog' ),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'The resulting plugin activation status.', 'abilities-catalog' ),
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
				),
				'show_in_rest' => true,
				'screen'       => 'plugins.php',
			),
		);
	}

	/**
	 * Permission check mirroring the plugins controller's update gate.
	 *
	 * Requires `activate_plugins` and the object-level `deactivate_plugin` capability
	 * for the target plugin file, matching `plugin_status_permission_check()`.
	 * Returns false when the required `plugin` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may deactivate the plugin.
	 */
	public function hasPermission( $input ): bool {
		$input  = is_array( $input ) ? $input : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $plugin ) {
			return false;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}

		// The route appends ".php"; the object capability is checked against the file.
		$file = plugin_basename( sanitize_text_field( $plugin . '.php' ) );

		return current_user_can( 'deactivate_plugin', $file );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * Builds the route by concatenation so the slash in the plugin path is preserved.
	 * Surfaces any REST error (not found, capability, deactivation failure) unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The plugin file path and resulting status, or the REST error.
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

		$request = new WP_REST_Request( 'POST', '/wp/v2/plugins/' . $plugin );
		$request->set_param( 'status', 'inactive' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'plugin' => (string) ( $data['plugin'] ?? $plugin ),
			'status' => (string) ( $data['status'] ?? '' ),
		);
	}
}
