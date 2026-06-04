<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `templates/get-global-styles`.
 *
 * Returns the global styles (`wp_global_styles` CPT) for the active theme. The
 * id is resolved from core via
 * {@see \WP_Theme_JSON_Resolver::get_user_global_styles_post_id()}, then the
 * record is fetched through `GET /wp/v2/global-styles/<id>`. Read-only.
 *
 * @since 0.1.0
 */
final class GetGlobalStyles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/get-global-styles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Global Styles', 'abilities-catalog' ),
			'description'         => __( 'Returns the global styles (settings and styles) for the active theme.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The global styles post ID for the active theme.', 'abilities-catalog' ),
					),
					'settings' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The theme.json-shaped settings overrides.', 'abilities-catalog' ),
					),
					'styles'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The theme.json-shaped style overrides.', 'abilities-catalog' ),
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
		if (
			! class_exists( 'WP_Theme_JSON_Resolver' )
			|| ! method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' )
		) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'Global styles are not available on this site.', 'abilities-catalog' ),
				array( 'status' => 501 )
			);
		}

		$id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $id <= 0 ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'No global styles record exists for the active theme.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/' . $id );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
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
