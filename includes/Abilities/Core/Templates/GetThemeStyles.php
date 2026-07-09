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
 * Read ability: `og-templates/get-theme-styles`.
 *
 * Wraps `GET /wp/v2/global-styles/themes/<stylesheet>` via the Abilities REST
 * Adapter. Returns the **active theme's** effective theme-level global settings and
 * styles: the merged result of core defaults, per-block defaults, and the theme's own
 * `theme.json` plus classic-theme supports (user overrides excluded). The core route
 * serves the active theme only — a non-active `stylesheet` always 404s — so
 * `stylesheet` is an optional explicit active-theme identifier; leave it empty and the
 * {@see fillStylesheet()} `input_callback` resolves the active theme. This is the
 * theme-level baseline, distinct from `og-templates/get-global-styles`, which returns
 * the user's raw override record layered on top.
 *
 * The input and output schemas are OVERRIDDEN to the catalog's narrow contract; the
 * adapter substitutes `stylesheet` into the route's path capture and {@see shapeOutput()}
 * reshapes the body, reporting the canonical `get_stylesheet()` and casting the
 * `settings`/`styles` sections to objects so an empty section serializes as `{}`.
 *
 * The `require_permission` floor uses `edit_theme_options`. The wrapped route's own
 * `get_theme_item_permissions_check` accepts the broader `edit_posts` OR any
 * REST-enabled post type's edit cap OR `edit_theme_options`, so the catalog floor is
 * STRICTER and must be kept to preserve the catalog's contract. Read-only.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/global-styles/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)',
				'method'             => 'GET',
				'label'              => __( 'Get Theme Styles', 'abilities-catalog' ),
				'description'        => __( 'Returns the active theme\'s effective theme-level global settings and styles (color palette, typography, spacing, element styles). The value merges core defaults, per-block defaults, and the theme\'s theme.json plus classic-theme supports; user overrides are excluded. The core route serves the active theme only. This is the theme-level baseline, not the user\'s raw override record returned by get-global-styles.', 'abilities-catalog' ),
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
				'require_permission' => array( $this, 'requirePermission' ),
				'input_callback'     => array( $this, 'fillStylesheet' ),
				'output_callback'    => array( $this, 'shapeOutput' ),
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
	 * Permission floor: `edit_theme_options` (catalog capability for global styles).
	 *
	 * The wrapped route's own `get_theme_item_permissions_check` accepts the broader
	 * `edit_posts` (or any REST post type's edit cap) OR `edit_theme_options`. This
	 * floor keeps the catalog's narrower `edit_theme_options` requirement — strictly
	 * tighter, never weaker. A truthy verdict defers to the route's own check at
	 * dispatch, which stays the authority.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True if the current user may read theme global styles.
	 */
	public function requirePermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Defaults the `stylesheet` path capture to the active theme.
	 *
	 * Wired as the adapter's `input_callback`, it runs after input validation. When the
	 * caller omits `stylesheet` (or passes an empty string), this injects the active
	 * theme's `get_stylesheet()` so the route's `/themes/<stylesheet>` path can be
	 * built. The route serves the active theme only, so any other value 404s anyway.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params with `stylesheet` guaranteed non-empty.
	 */
	public function fillStylesheet( array $params ): array {
		$stylesheet = isset( $params['stylesheet'] ) ? trim( (string) $params['stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			$params['stylesheet'] = get_stylesheet();
		}

		return $params;
	}

	/**
	 * Reshapes the theme global-styles body to the catalog's flat contract.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. Core only
	 * ever serves the active theme, so the `stylesheet` is reported as the canonical
	 * `get_stylesheet()` rather than echoing the (possibly URL-encoded) input. The
	 * `settings` and `styles` sections are cast to objects so an empty section
	 * serializes as `{}` (matching the `type: object` schema); an empty PHP array
	 * would serialize as `[]`. `$input` and `$response` are part of the callback
	 * signature but unused here.
	 *
	 * @param mixed               $data     The REST theme global-styles body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped theme styles.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'stylesheet' => get_stylesheet(),
			'settings'   => (object) ( is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ),
			'styles'     => (object) ( is_array( $data['styles'] ?? null ) ? $data['styles'] : array() ),
		);
	}
}
