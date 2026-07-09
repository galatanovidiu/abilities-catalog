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
 * Destructive write ability: `og-templates/update-global-styles`.
 *
 * Wraps `POST /wp/v2/global-styles/<id>` via the Abilities REST Adapter, where
 * `<id>` is the `wp_global_styles` post id for the active theme (resolve it first
 * with `og-templates/get-global-styles`).
 *
 * This is annotated DESTRUCTIVE because it replaces the active theme's global
 * settings and styles (`theme.json`-shaped overrides), changing the appearance of
 * the whole site. The change is recoverable (the override record can be reset) but
 * has a high blast radius. The browser exposes it only when both the adapter write
 * setting and destructive setting are on.
 *
 * The `require_permission` floor mirrors
 * {@see \WP_REST_Global_Styles_Controller::update_item_permissions_check()}, which
 * requires object-level `edit_post` on the global-styles post id — for
 * `wp_global_styles` that maps to `edit_theme_options` with no owner split, so the
 * coarse floor is exactly what core requires. The route re-checks `edit_post`
 * underneath and surfaces a missing-id 404. The route does NOT hard-reject custom
 * CSS for users lacking `edit_css`: custom CSS is kses-filtered via
 * {@see \WP_Theme_JSON::remove_insecure_properties()} (kept only for `edit_css`
 * users, otherwise stripped). To stay no weaker than the controller's intent, the
 * floor additionally requires `edit_css` when the input includes a `styles.css`
 * key — an added hard gate, strictly tighter, never looser.
 *
 * @since 0.3.0
 */
final class UpdateGlobalStyles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/update-global-styles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/global-styles/(?P<id>[\/\d+]+)',
				'method'             => 'POST',
				'label'              => __( 'Update Global Styles', 'abilities-catalog' ),
				'description'        => __( 'Updates the active theme global styles (settings and styles) by the global-styles post id. Changes site-wide appearance. Each provided top-level settings or styles object REPLACES that stored section wholesale (not a deep merge); read the current record first with og-templates/get-global-styles and send a complete replacement for whichever section you change.', 'abilities-catalog' ),
				'category'           => 'og-core-templates',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => __( 'The global styles post ID for the active theme. Get it from og-templates/get-global-styles, or from og-templates/init-global-styles when no record exists yet.', 'abilities-catalog' ),
						),
						'settings' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'The theme.json-shaped settings overrides to store. REPLACES the entire stored settings section wholesale; sibling branches you omit are dropped. Send a complete replacement.', 'abilities-catalog' ),
						),
						'styles'   => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'The theme.json-shaped style overrides to store. REPLACES the entire stored styles section wholesale; sibling branches you omit (e.g. styles.css, styles.blocks) are dropped. Send a complete replacement. A "css" key holds custom CSS and requires the edit_css capability.', 'abilities-catalog' ),
						),
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'The global styles record title.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => __( 'The global styles post ID.', 'abilities-catalog' ),
						),
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'The global styles record title after the update.', 'abilities-catalog' ),
						),
						'settings' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'The stored theme.json-shaped settings section after the update (empty object when none).', 'abilities-catalog' ),
						),
						'styles'   => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'The stored theme.json-shaped styles section after the update (empty object when none).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'require_permission' => array( $this, 'requirePermission' ),
				'output_callback'    => array( $this, 'shapeOutput' ),
				'meta'               => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'site-editor.php',
				),
			)
		);
	}

	/**
	 * Permission floor: coarse `edit_theme_options` (+ `edit_css` for custom CSS).
	 *
	 * For `wp_global_styles`, `edit_post` maps to `edit_theme_options` with no
	 * owner-vs-others split, so this coarse, object-independent check is exactly what
	 * core requires — never stricter, never weaker. The object decision (and a
	 * missing-id 404) is left to the wrapped `POST /wp/v2/global-styles/<id>` route
	 * at dispatch. The route does NOT re-check `edit_css`: custom CSS is kses-filtered,
	 * so when the input carries a `styles.css` key this floor keeps the added
	 * `edit_css` hard gate — never weaker than the controller's intent.
	 *
	 * @param mixed $input The raw ability input.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}

		return ! $this->hasCustomCss( $input ) || current_user_can( 'edit_css' );
	}

	/**
	 * Casts the updated global-styles body to the catalog's output shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. `settings` and
	 * `styles` are cast to objects so an empty section serializes as `{}` (a JSON
	 * object), matching the `type: object` output schema; an empty PHP array would
	 * serialize as `[]` and fail output validation. `$input['id']` is the fallback id.
	 * `$response` is part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST global-styles body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped global-styles record.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = absint( $input['id'] ?? 0 );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		return array(
			'id'       => (int) ( $data['id'] ?? $id ),
			'title'    => (string) $title,
			'settings' => (object) ( is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ),
			'styles'   => (object) ( is_array( $data['styles'] ?? null ) ? $data['styles'] : array() ),
		);
	}

	/**
	 * Reports whether the input carries a `styles.css` key.
	 *
	 * Core branches on key presence, not value (the documented contract is "a css
	 * key requires edit_css"), so the gate fires whenever a string `css` key is
	 * present — including an explicit empty string.
	 *
	 * @param array<string,mixed> $input The raw ability input.
	 * @return bool True if a `styles.css` string key is present.
	 */
	private function hasCustomCss( array $input ): bool {
		return isset( $input['styles'] )
			&& is_array( $input['styles'] )
			&& array_key_exists( 'css', $input['styles'] )
			&& is_string( $input['styles']['css'] );
	}
}
