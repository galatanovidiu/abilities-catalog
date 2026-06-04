<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `plugins/get-plugin`.
 *
 * Wraps `GET /wp/v2/plugins/<plugin>` via `rest_do_request()` and shapes the
 * response into a flat field set. The `plugin` input is the plugin file path
 * without the `.php` extension (for example `webmcp-adapter/webmcp-adapter`);
 * the route is built by concatenation so the slash inside the path is preserved
 * and not URL-encoded.
 *
 * @since 0.1.0
 */
final class GetPlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'plugins/get-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Plugin', 'abilities-catalog' ),
			'description'         => __( 'Returns details about a single installed plugin by its file path.', 'abilities-catalog' ),
			'category'            => 'plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path without the .php extension, for example "webmcp-adapter/webmcp-adapter".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'plugin', 'status' ),
				'properties'           => array(
					'plugin'       => array(
						'type'        => 'string',
						'description' => __( 'The plugin file path.', 'abilities-catalog' ),
					),
					'status'       => array(
						'type'        => 'string',
						'description' => __( 'The plugin activation status.', 'abilities-catalog' ),
					),
					'name'         => array(
						'type'        => 'string',
						'description' => __( 'The plugin name.', 'abilities-catalog' ),
					),
					'version'      => array(
						'type'        => 'string',
						'description' => __( 'The plugin version.', 'abilities-catalog' ),
					),
					'description'  => array(
						'type'        => 'string',
						'description' => __( 'The plugin description.', 'abilities-catalog' ),
					),
					'author'       => array(
						'type'        => 'string',
						'description' => __( 'The plugin author.', 'abilities-catalog' ),
					),
					'plugin_uri'   => array(
						'type'        => 'string',
						'description' => __( 'The plugin home page URL.', 'abilities-catalog' ),
					),
					'network_only' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the plugin can only be activated network-wide.', 'abilities-catalog' ),
					),
					'requires_wp'  => array(
						'type'        => 'string',
						'description' => __( 'The minimum required WordPress version.', 'abilities-catalog' ),
					),
					'requires_php' => array(
						'type'        => 'string',
						'description' => __( 'The minimum required PHP version.', 'abilities-catalog' ),
					),
					'textdomain'   => array(
						'type'        => 'string',
						'description' => __( 'The plugin text domain.', 'abilities-catalog' ),
					),
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
	 * Permission check: the current user may manage plugin activation.
	 *
	 * Encodes the catalog capability for `plugins/get-plugin` (`activate_plugins`).
	 * Returns false when the required `plugin` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the plugin.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( empty( $input['plugin'] ) ) {
			return false;
		}

		return current_user_can( 'activate_plugins' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat plugin fields, or the REST error.
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

		$request  = new WP_REST_Request( 'GET', '/wp/v2/plugins/' . $plugin );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'plugin'       => (string) ( $data['plugin'] ?? $plugin ),
			'status'       => (string) ( $data['status'] ?? '' ),
			'name'         => $this->coerceString( $data['name'] ?? '' ),
			'version'      => (string) ( $data['version'] ?? '' ),
			'description'  => $this->coerceString( $data['description'] ?? '' ),
			'author'       => $this->coerceString( $data['author'] ?? '' ),
			'plugin_uri'   => (string) ( $data['plugin_uri'] ?? '' ),
			'network_only' => (bool) ( $data['network_only'] ?? false ),
			'requires_wp'  => (string) ( $data['requires_wp'] ?? '' ),
			'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			'textdomain'   => (string) ( $data['textdomain'] ?? '' ),
		);
	}

	/**
	 * Coerces a REST field that may be a string or a `raw`/`rendered` array to a string.
	 *
	 * @param mixed $value The raw field value.
	 * @return string The string value.
	 */
	private function coerceString( $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['rendered'] ?? ( $value['raw'] ?? '' );
		}

		return (string) $value;
	}
}
