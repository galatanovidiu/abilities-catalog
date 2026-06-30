<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/get-global-styles`.
 *
 * Wraps `GET /wp/v2/global-styles/<id>` via the Abilities REST Adapter. The id is
 * resolved INTERNALLY (the caller never passes one): {@see shapeInput()} looks up the
 * active theme's existing user global-styles record via
 * {@see \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles()} with
 * `$create_post = false` (so this read never inserts a row) and injects the id into the
 * params, which the adapter substitutes into the route's `id` capture. Because the id
 * is injected — not supplied — the input schema is a no-input schema (it does NOT mark
 * `id` required, or validation would reject the call before `shapeInput()` runs).
 *
 * The ability returns the active theme's user global-style overrides — the
 * `wp_global_styles` CPT record holding only the settings/styles the user changed in
 * the Site Editor. It does NOT merge the theme's baseline `theme.json`; use
 * `og-templates/get-theme-styles` for the theme baseline. {@see shapeInput()} returns a
 * 404 `WP_Error` when the active theme has no overrides record yet (a read of "the
 * user's overrides" is honestly empty then). {@see shapeOutput()} flattens the rendered
 * title and casts the empty settings/styles sections to JSON objects. Permission
 * delegates to the route's own check (no `require_permission` floor): the global-styles
 * route requires `read_post` on the record, which for `wp_global_styles` maps to
 * `edit_theme_options` — surfacing the route's specific `rest_cannot_view` instead of
 * the Abilities API collapsing it into a generic permission failure. Read-only.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/global-styles/(?P<id>[\/\d+]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Global Styles', 'abilities-catalog' ),
				'description'     => __( 'Returns the active theme\'s user global-style overrides (the settings and styles changed in the Site Editor), not the theme.json baseline. Use og-templates/get-theme-styles for the theme baseline.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => (object) array(),
					'additionalProperties' => false,
					'default'              => (object) array(),
				),
				'output_schema'   => array(
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
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
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
	 * Resolves the active-theme global-styles id and injects it for the route path.
	 *
	 * Wired as the adapter's `input_callback`; runs once at dispatch, as the current
	 * user. The caller supplies no id, so this resolves the EXISTING user
	 * global-styles record without creating one — core's
	 * {@see \WP_Theme_JSON_Resolver::get_user_global_styles_post_id()} passes
	 * `$create_post = true` and inserts a `wp_global_styles` row on first access, which
	 * would make this read-only ability write to the database; querying with
	 * `$create_post = false` instead keeps the read pure. The resolved id is added to
	 * the params so the adapter substitutes it into the route's `id` capture. Returns a
	 * `WP_Error` (501 when global styles are unavailable, 404 when the active theme has
	 * no overrides record yet) to reject before dispatch.
	 *
	 * @param array<string,mixed> $params The validated ability input (no caller-supplied id).
	 * @return array<string,mixed>|\WP_Error The params with the resolved `id` injected, or an error.
	 */
	public function shapeInput( array $params ) {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'Global styles are not available on this site.', 'abilities-catalog' ),
				array( 'status' => 501 )
			);
		}

		$user_cpt = \WP_Theme_JSON_Resolver::get_user_data_from_wp_global_styles( wp_get_theme(), false );
		$id       = isset( $user_cpt['ID'] ) ? (int) $user_cpt['ID'] : 0;
		if ( $id <= 0 ) {
			return new WP_Error(
				'global_styles_unavailable',
				__( 'No global styles record exists for the active theme yet. Call og-templates/init-global-styles to create one, then retry.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$params['id'] = $id;

		return $params;
	}

	/**
	 * Maps the REST global-styles body to the catalog's flat output shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. The rendered
	 * `title` is flattened from its nested REST object, and the empty `settings`/`styles`
	 * sections are cast to objects so they serialize as `{}` (a JSON object) — an empty
	 * PHP array would serialize as `[]` and fail the `type: object` output schema.
	 * `$response` is part of the callback signature but unused — the body carries
	 * everything this shape needs.
	 *
	 * @param mixed               $data     The REST global-styles body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat global-styles fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		// Cast to objects so an empty result serializes as `{}` (a JSON object),
		// matching the `type: object` output schema; an empty PHP array would
		// serialize as `[]` and fail output validation.
		return array(
			'id'       => (int) ( $data['id'] ?? 0 ),
			'settings' => (object) ( is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ),
			'styles'   => (object) ( is_array( $data['styles'] ?? null ) ? $data['styles'] : array() ),
			'title'    => (string) $title,
		);
	}
}
