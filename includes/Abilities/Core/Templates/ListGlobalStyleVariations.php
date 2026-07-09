<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/list-global-style-variations`.
 *
 * Wraps `GET /wp/v2/global-styles/themes/<stylesheet>/variations` via the Abilities
 * REST Adapter. Returns the style variations a theme ships (the alternate palettes
 * and type sets a user can switch between in the Site Editor's Styles panel), each
 * with its title and the theme.json-shaped settings and styles it would apply. The
 * core route serves the active theme only — a non-active `stylesheet` always 404s —
 * so `stylesheet` is an optional explicit active-theme identifier; leave it empty to
 * resolve the active theme automatically. For a child theme, the list may include
 * variations inherited from the parent theme.
 *
 * `stylesheet` is the route's required path capture, but the catalog exposes it as an
 * optional input (default the active theme); {@see shapeInput()} fills it in before
 * dispatch, so the input schema does NOT mark it required. The route's handler is
 * `get_theme_items` (not a controller `get_items`), so the adapter returns the body
 * as a plain array — not the `{ items, total, total_pages }` collection envelope —
 * and {@see shapeOutput()} re-wraps that array into the catalog's `{ stylesheet, items }`
 * shape. The catalog guard (`edit_theme_options`) is stricter than the route's own
 * check (`edit_theme_options` OR a post-type edit cap), so a `require_permission` floor
 * preserves it; the route's check still runs at dispatch.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/global-styles/themes/(?P<stylesheet>[\/\s%\w\.\(\)\[\]\@_\-]+)/variations',
				'method'             => 'GET',
				'label'              => __( 'List Global Style Variations', 'abilities-catalog' ),
				'description'        => __( 'Lists the style variations a theme provides (alternate palettes and typography sets selectable in the Site Editor Styles panel). Each variation includes its title and the theme.json settings and styles it applies. The core route serves the active theme only; any non-active stylesheet returns a 404. For a child theme, the list may include variations inherited from the parent theme.', 'abilities-catalog' ),
				'category'           => 'og-core-templates',
				'input_schema'       => array(
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
				'output_schema'      => array(
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
										'type'        => 'object',
										'additionalProperties' => true,
										'description' => __( 'The theme.json-shaped settings the variation applies.', 'abilities-catalog' ),
									),
									'styles'      => array(
										'type'        => 'object',
										'additionalProperties' => true,
										'description' => __( 'The theme.json-shaped styles the variation applies.', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of style variations.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'input_callback'     => array( $this, 'shapeInput' ),
				'output_callback'    => array( $this, 'shapeOutput' ),
				'require_permission' => array( $this, 'requirePermission' ),
				'meta'               => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Resolves the active-theme stylesheet into the route's `stylesheet` path capture.
	 *
	 * Wired as the adapter's `input_callback`; runs once at dispatch, as the current
	 * user. The catalog exposes `stylesheet` as optional (default the active theme),
	 * but the route requires it in the path, so this fills it in when the caller leaves
	 * it empty. The core route serves the active theme only.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with a resolved `stylesheet`.
	 */
	public function shapeInput( array $params ): array {
		$stylesheet = isset( $params['stylesheet'] ) ? trim( (string) $params['stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			$stylesheet = get_stylesheet();
		}

		$params['stylesheet'] = $stylesheet;

		return $params;
	}

	/**
	 * Permission floor: `edit_theme_options` (the catalog capability for global styles).
	 *
	 * Wired as the adapter's `require_permission` guard. The theme global-styles route
	 * accepts `edit_theme_options` OR a post-type edit capability; this floor keeps the
	 * catalog's stricter `edit_theme_options` contract. The route's own check still runs
	 * at dispatch and stays the authority.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True if the current user may read theme style variations.
	 */
	public function requirePermission( $input = null ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Re-wraps the route's variation list into the catalog's `{ stylesheet, items }` shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. The route's
	 * handler is `get_theme_items` (not a controller `get_items`), so the adapter does
	 * not wrap the body in the `{ items, total, total_pages }` envelope — `$data` is the
	 * plain array of variations. Each variation's `settings` and `styles` are cast to
	 * objects so an empty value serializes as `{}` (the `type: object` item schema), not
	 * `[]`; `slug` and `description` are surfaced only when the source defines them. The
	 * resolved active-theme stylesheet is read from `$input` (the original ability input,
	 * before `shapeInput()`), falling back to the active theme. `$response` is part of the
	 * callback signature but unused — the body carries the variations.
	 *
	 * @param mixed               $data     The route's variation list (plain array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The `{ stylesheet, items }` shape.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$stylesheet = isset( $input['stylesheet'] ) ? trim( (string) $input['stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			$stylesheet = get_stylesheet();
		}

		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $variation ) {
			if ( ! is_array( $variation ) ) {
				continue;
			}

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
