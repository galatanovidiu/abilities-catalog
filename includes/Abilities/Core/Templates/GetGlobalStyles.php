<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/get-global-styles`.
 *
 * Returns the active theme's user global-style overrides — the `wp_global_styles`
 * CPT record holding only the settings/styles the user changed in the Site Editor.
 * It does NOT merge the theme's baseline `theme.json`; use
 * `og-templates/get-theme-styles` for the theme baseline. The existing record is
 * resolved from core via
 * {@see \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles()} with
 * `$create_post = false` (so this read never inserts a row), then fetched through
 * `GET /wp/v2/global-styles/<id>`. Returns 404 when the active theme has no
 * overrides record yet. Read-only.
 *
 * @since 0.1.0
 */
final class GetGlobalStyles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/get-global-styles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Global Styles', 'abilities-catalog' ),
			'description'         => __( 'Returns the active theme\'s user global-style overrides (the settings and styles changed in the Site Editor), not the theme.json baseline. Use og-templates/get-theme-styles for the theme baseline.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The user global-styles post ID for the active theme.', 'abilities-catalog' ),
					),
					'settings' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The user\'s theme.json-shaped settings overrides (empty object when the user has no overrides; not the theme.json baseline).', 'abilities-catalog' ),
					),
					'styles'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The user\'s theme.json-shaped style overrides (empty object when the user has no overrides; not the theme.json baseline).', 'abilities-catalog' ),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __( 'The global styles record title.', 'abilities-catalog' ),
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
	 * Permission check: `edit_theme_options` (catalog capability for global styles).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the global styles.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability: resolve the active-theme global styles id, then fetch it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped global styles, or an error.
	 */
	public function execute( $input = null ) {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'Global styles are not available on this site.', 'abilities-catalog' ),
				array( 'status' => 501 )
			);
		}

		// Resolve the EXISTING user global-styles record without creating one.
		// WP_Theme_JSON_Resolver::get_user_global_styles_post_id() passes
		// $create_post = true and inserts a wp_global_styles row on first access,
		// which would make this read-only ability write to the database. Query with
		// $create_post = false instead and 404 when the active theme has no overrides
		// record yet (a read of "the user's overrides" is honestly empty then).
		$user_cpt = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), false );
		$id       = isset( $user_cpt['ID'] ) ? (int) $user_cpt['ID'] : 0;
		if ( $id <= 0 ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'No global styles record exists for the active theme yet. Call og-templates/init-global-styles to create one, then retry.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $id );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		// Cast to objects so an empty result serializes as `{}` (a JSON object),
		// matching the `type: object` output schema; an empty PHP array would
		// serialize as `[]` and fail output validation.
		return array(
			'id'       => (int) ( $data['id'] ?? $id ),
			'settings' => (object) ( is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ),
			'styles'   => (object) ( is_array( $data['styles'] ?? null ) ? $data['styles'] : array() ),
			'title'    => (string) $title,
		);
	}
}
