<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use GalatanOvidiu\AbilitiesCatalog\Support\FilesystemGuard;
use GalatanOvidiu\AbilitiesCatalog\Support\SourceValidator;
use GalatanOvidiu\AbilitiesCatalog\Support\UpgradeRunner;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T3 dangerous write ability: `themes/install-theme`.
 *
 * Net-new (no themes REST install route): installs a wordpress.org-directory theme via
 * core's `Theme_Upgrader`. Input is restricted to a clean wp.org directory slug by
 * {@see SourceValidator} — no ZIP URL, remote URL, or local path can reach the upgrader.
 * The filesystem must be directly writable ({@see FilesystemGuard}); the install runs
 * behind the serialized upgrader lock ({@see UpgradeRunner}). Installing code from the
 * directory is dangerous, so this ability is annotated destructive and dangerous and is
 * exposed to the browser only when the adapter's write AND destructive settings are both
 * on. Capability is the hard guard in all cases. The outer `/run` call is POST.
 *
 * @since 0.5.0
 */
final class InstallTheme implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'themes/install-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Install Theme', 'abilities-catalog' ),
			'description'         => __( 'Installs a theme from the wordpress.org directory by its slug (no ZIP, URL, or file path). Requires direct filesystem write access. Installing code from the directory changes the site, so this is a dangerous operation. On multisite the install affects every site in the network.', 'abilities-catalog' ),
			'category'            => 'themes',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'description' => __( 'The wordpress.org theme directory slug to install, for example "twentytwentyfive".', 'abilities-catalog' ),
						'minLength'   => 1,
						'pattern'     => '^[a-z0-9-]+$',
					),
				),
				'required'             => array( 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'installed', 'stylesheet', 'name' ),
				'properties'           => array(
					'installed'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the theme was installed.', 'abilities-catalog' ),
					),
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __( 'The stylesheet (directory name) of the installed theme.', 'abilities-catalog' ),
					),
					'name'       => array(
						'type'        => 'string',
						'description' => __( 'The display name of the installed theme.', 'abilities-catalog' ),
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
				'screen'       => 'themes.php',
			),
		);
	}

	/**
	 * Permission check: the current user may install themes.
	 *
	 * Encodes the catalog capability for `themes/install-theme` (`install_themes`).
	 * Returns false when the required `slug` input is missing.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may install themes.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( empty( $input['slug'] ) ) {
			return false;
		}

		return current_user_can( 'install_themes' );
	}

	/**
	 * Executes the ability by installing a wordpress.org-directory theme.
	 *
	 * The slug is validated first, then the filesystem must be directly writable. The
	 * download link is resolved from the wordpress.org themes API; the install runs
	 * behind the serialized upgrader lock. Any guard or upgrader error is returned as a
	 * `WP_Error`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Install result, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = isset( $input['slug'] ) ? (string) $input['slug'] : '';

		$slug = SourceValidator::slug( $slug );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$fs = FilesystemGuard::ensureDirect( get_theme_root() );
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		AdminIncludes::load( 'theme', 'class-wp-upgrader', 'class-theme-upgrader', 'file' );

		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			// `themes_api()` returns `themes_api_failed` with no HTTP status on an
			// API/transport failure; backfill one so the caller can branch on it.
			$data = $api->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$api->add_data( array( 'status' => 502 ) );
			}

			return $api;
		}

		if ( empty( $api->download_link ) ) {
			return new WP_Error(
				'abilities_catalog_theme_not_found',
				__( 'No wordpress.org theme found for that slug.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$result = UpgradeRunner::withLock(
			get_theme_root(),
			static function () use ( $api ) {
				$upgrader = new \Theme_Upgrader( UpgradeRunner::skin() );

				$installed = $upgrader->install( $api->download_link );
				if ( true !== $installed ) {
					// `install()` returns `false`/`null` or a `WP_Error` on failure;
					// pass the failure value through so the caller can normalize it.
					return $installed;
				}

				// Resolve the canonical installed handle from the extracted package
				// (`destination_name`), which can differ from the request slug.
				return $upgrader->theme_info();
			}
		);

		if ( is_wp_error( $result ) ) {
			// Core's install path can return path-bearing errors such as
			// `folder_exists` (already installed) or `mkdir_failed_destination`,
			// where the error data is an absolute filesystem path. Preserve the
			// stable code and message, strip the path-leaking data, and attach an
			// explicit HTTP status for client branching.
			$status = 'folder_exists' === $result->get_error_code() ? 409 : 500;

			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => $status )
			);
		}

		if ( ! $result instanceof \WP_Theme || ! $result->exists() ) {
			return new WP_Error(
				'abilities_catalog_install_failed',
				__( 'The theme installation did not complete.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'installed'  => true,
			'stylesheet' => (string) $result->get_stylesheet(),
			'name'       => (string) $result->get( 'Name' ),
		);
	}
}
