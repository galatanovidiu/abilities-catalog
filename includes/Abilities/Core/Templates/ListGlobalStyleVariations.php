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
 * Read ability: `og-templates/list-global-style-variations`.
 *
 * Wraps `GET /wp/v2/global-styles/themes/<stylesheet>/variations` via
 * `rest_do_request()`. Returns the style variations a theme ships (the alternate
 * palettes and type sets a user can switch between in the Site Editor's Styles
 * panel), each with its title and the theme.json-shaped settings and styles it
 * would apply. The core route serves the active theme only — a non-active
 * `stylesheet` always 404s — so `stylesheet` is an optional explicit active-theme
 * identifier; leave it empty to resolve the active theme automatically. For a
 * child theme, the list may include variations inherited from the parent theme.
 * Read-only.
 *
 * @since 0.5.0
 */
final class ListGlobalStyleVariations implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-global-style-variations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Global Style Variations', 'abilities-catalog' ),
			'description'         => __( 'Lists the style variations a theme provides (alternate palettes and typography sets selectable in the Site Editor Styles panel). Each variation includes its title and the theme.json settings and styles it applies. The core route serves the active theme only; any non-active stylesheet returns a 404. For a child theme, the list may include variations inherited from the parent theme.', 'abilities-catalog' ),
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
				'required'             => array( 'stylesheet', 'items' ),
				'properties'           => array(
					'stylesheet' => array(
						'type'        => 'string',
						'description' => __( 'The active theme stylesheet used for the request.', 'abilities-catalog' ),
					),
					'items'      => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'title', 'settings', 'styles' ),
							'properties'           => array(
								'title'       => array(
									'type'        => 'string',
									'description' => __( 'The variation title.', 'abilities-catalog' ),
								),
								'slug'        => array(
									'type'        => 'string',
									'description' => __( 'The variation slug, a stable identifier. Present when the source theme.json defines it.', 'abilities-catalog' ),
								),
								'description' => array(
									'type'        => 'string',
									'description' => __( 'The variation description. Present when the source theme.json defines it.', 'abilities-catalog' ),
								),
								'settings'    => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => __( 'The theme.json-shaped settings the variation applies.', 'abilities-catalog' ),
								),
								'styles'      => array(
									'type'                 => 'object',
									'additionalProperties' => true,
									'description'          => __( 'The theme.json-shaped styles the variation applies.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __( 'The list of style variations.', 'abilities-catalog' ),
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
	 * @return bool True if the current user may read theme style variations.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request and shaping the result.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped variations, or the REST error.
	 */
	public function execute( $input = null ) {
		$input      = is_array( $input ) ? $input : array();
		$stylesheet = isset( $input['stylesheet'] ) ? trim( (string) $input['stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			$stylesheet = get_stylesheet();
		}

		$request = new WP_REST_Request( 'GET', '/wp/v2/global-styles/themes/' . $stylesheet . '/variations' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $variation ) {
			$item = array(
				'title'    => (string) ( $variation['title'] ?? '' ),
				'settings' => (object) ( is_array( $variation['settings'] ?? null ) ? $variation['settings'] : array() ),
				'styles'   => (object) ( is_array( $variation['styles'] ?? null ) ? $variation['styles'] : array() ),
			);

			// `slug` and `description` are optional top-level theme.json keys
			// (WP_Theme_JSON, 6.3.0+); emit them only when the source defines them.
			if ( isset( $variation['slug'] ) && is_string( $variation['slug'] ) ) {
				$item['slug'] = $variation['slug'];
			}
			if ( isset( $variation['description'] ) && is_string( $variation['description'] ) ) {
				$item['description'] = $variation['description'];
			}

			$items[] = $item;
		}

		return array(
			'stylesheet' => $stylesheet,
			'items'      => $items,
		);
	}
}
