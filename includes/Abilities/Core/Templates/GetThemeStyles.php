<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/get-theme-styles`.
 *
 * Wraps `GET /wp/v2/global-styles/themes/<stylesheet>` via `rest_do_request()`.
 * Returns the **active theme's** effective theme-level global settings and styles:
 * the merged result of core defaults, per-block defaults, and the theme's own
 * `theme.json` plus classic-theme supports (user overrides excluded). The core
 * route serves the active theme only — a non-active `stylesheet` always 404s — so
 * `stylesheet` is an optional explicit active-theme identifier; leave it empty to
 * resolve the active theme automatically. This is the theme-level baseline,
 * distinct from `og-templates/get-global-styles`, which returns the user's raw
 * override record layered on top. Read-only.
 *
 * @since 0.5.0
 */
final class GetThemeStyles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/get-theme-styles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Theme Styles', 'abilities-catalog' ),
			'description'         => __( 'Returns the active theme\'s effective theme-level global settings and styles (color palette, typography, spacing, element styles). The value merges core defaults, per-block defaults, and the theme\'s theme.json plus classic-theme supports; user overrides are excluded. The core route serves the active theme only. This is the theme-level baseline, not the user\'s raw override record returned by get-global-styles.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Optional active-theme stylesheet (directory name). The route serves the active theme only, so any non-active value returns a 404. Leave empty to resolve the active theme automatically.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'stylesheet' ),
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __( 'The theme stylesheet these styles belong to.', 'abilities-catalog' ),
					),
					'settings'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The theme.json-shaped settings (design tokens: palette, typography, spacing).', 'abilities-catalog' ),
					),
					'styles'     => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The theme.json-shaped styles (element and block style rules).', 'abilities-catalog' ),
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
	 * The theme global-styles route accepts `edit_posts` or `edit_theme_options`;
	 * this guard uses `edit_theme_options` and is never weaker than that route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read theme global styles.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped theme styles, or the REST error.
	 */
	public function execute( $input = null ) {
		$input      = is_array( $input ) ? $input : array();
		$stylesheet = isset( $input['stylesheet'] ) ? trim( (string) $input['stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			$stylesheet = get_stylesheet();
		}

		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $stylesheet );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		// Cast to objects so empty results serialize as `{}` (matching the
		// `type: object` schema); an empty PHP array would serialize as `[]`.
		// Core only ever serves the active theme, so report its canonical
		// stylesheet rather than echoing the (possibly URL-encoded) input.
		return array(
			'stylesheet' => get_stylesheet(),
			'settings'   => (object) ( is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ),
			'styles'     => (object) ( is_array( $data['styles'] ?? null ) ? $data['styles'] : array() ),
		);
	}
}
