<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\FilesystemGuard;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use GalatanOvidiu\AbilitiesCatalog\Support\SourceValidator;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous write ability: `plugins/install-plugin`.
 *
 * Wraps `POST /wp/v2/plugins` with a `slug` via `rest_do_request()`, installing
 * (inactive) a wordpress.org-directory plugin. Installing brings new code onto the
 * site, so this ability is annotated dangerous and is exposed to the browser only
 * behind the third gate plus a per-ability opt-in. Input is restricted to a
 * wordpress.org directory slug by {@see SourceValidator::slug()}: no ZIP URL, remote
 * URL, or file path can pass. The filesystem must be directly writable
 * ({@see FilesystemGuard::ensureDirect()}); otherwise a generic 503 is returned with
 * no path or credential detail.
 *
 * @since 0.4.0
 */
final class InstallPlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'plugins/install-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Install Plugin', 'abilities-catalog' ),
			'description'         => __( 'Installs an inactive plugin from the wordpress.org directory by its slug. Installing brings new code onto the site.', 'abilities-catalog' ),
			'category'            => 'plugins',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'description' => __( 'The wordpress.org directory slug, for example "akismet".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'plugin', 'status', 'name' ),
				'properties'           => array(
					'plugin' => array(
						'type'        => 'string',
						'description' => __( 'The installed plugin file path.', 'abilities-catalog' ),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'The resulting plugin activation status.', 'abilities-catalog' ),
					),
					'name'   => array(
						'type'        => 'string',
						'description' => __( 'The installed plugin name.', 'abilities-catalog' ),
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
	 * Permission check mirroring the plugins controller's create gate.
	 *
	 * Requires the single-site primitive `install_plugins`, matching
	 * `create_item_permissions_check()`. On multisite, network plugin management is
	 * also required by core, but the primitive mirrored here is `install_plugins`.
	 * Returns false when the required `slug` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may install plugins.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['slug'] ) ? (string) $input['slug'] : '';

		if ( '' === $slug ) {
			return false;
		}

		return current_user_can( 'install_plugins' );
	}

	/**
	 * Executes the ability by validating the slug and dispatching the REST install.
	 *
	 * Validates the slug to a wordpress.org directory slug, requires a directly
	 * writable filesystem, then dispatches `POST /wp/v2/plugins`. Surfaces any REST
	 * error unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The installed plugin file, status, and name, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['slug'] ) ? (string) $input['slug'] : '';

		$slug = SourceValidator::slug( $slug );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$fs = FilesystemGuard::ensureDirect( WP_PLUGIN_DIR );
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/plugins' );
		$request->set_param( 'slug', $slug );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'plugin' => (string) ( $data['plugin'] ?? '' ),
			'status' => (string) ( $data['status'] ?? '' ),
			'name'   => (string) ( $data['name'] ?? '' ),
		);
	}
}
