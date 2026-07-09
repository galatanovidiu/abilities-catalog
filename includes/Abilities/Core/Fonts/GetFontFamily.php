<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-fonts/get-font-family`.
 *
 * Wraps `GET /wp/v2/font-families/<id>` via the Abilities REST Adapter. The input
 * schema is OVERRIDDEN to the catalog's closed shape — the path capture `id`
 * (integer, required) plus `context` (`view`/`edit`, default `view`) — so the
 * derived schema (which would expose `_fields` and the like) is replaced. The
 * output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}. Permission delegates to the route's own check (no
 * `require_permission` floor is set): the route already requires
 * `edit_theme_options` to read a font family, so conversion does not widen access.
 *
 * @since 0.1.0
 */
final class GetFontFamily implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-fonts/get-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/font-families/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Font Family', 'abilities-catalog' ),
				'description'     => __( 'Returns a single installed font family by ID, including its settings and font faces.', 'abilities-catalog' ),
				'category'        => 'og-core-fonts',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => __( 'The font family post ID. Discover IDs with `og-fonts/list-font-families`.', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the response fields: "view", "edit", or "embed". All output fields are returned in every context.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'                   => array(
							'type'        => 'integer',
							'description' => __( 'The font family post ID.', 'abilities-catalog' ),
						),
						'font_family_settings' => array(
							'type'                 => 'object',
							'properties'           => array(
								'name'       => array(
									'type'        => 'string',
									'description' => __( 'The human-readable font family name.', 'abilities-catalog' ),
								),
								'slug'       => array(
									'type'        => 'string',
									'description' => __( 'The font family slug.', 'abilities-catalog' ),
								),
								'fontFamily' => array(
									'type'        => 'string',
									'description' => __( 'The CSS font-family value.', 'abilities-catalog' ),
								),
								'preview'    => array(
									'type'        => 'string',
									'description' => __( 'URL to a preview image of the font family.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => true,
							'description'          => __( 'The font family settings (name, slug, fontFamily, optional preview).', 'abilities-catalog' ),
						),
						'font_faces'           => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'integer',
							),
							'description' => __( 'The font face post IDs belonging to this family.', 'abilities-catalog' ),
						),
						'theme_json_version'   => array(
							'type'        => 'integer',
							'description' => __( 'The theme.json schema version of the family.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'abilities_catalog' => array(
						'scope' => 'site',
					),
					'show_in_rest'      => true,
				),
			)
		);
	}

	/**
	 * Flattens the REST font-family body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST font-family body. `id` falls back to the requested `id` from `$input` when
	 * the body omits it; `font_family_settings` and `font_faces` default to empty
	 * arrays, and `theme_json_version` casts to an integer. `$response` is part of the
	 * callback signature but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST font-family body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat font-family fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = absint( $input['id'] ?? 0 );

		return array(
			'id'                   => (int) ( $data['id'] ?? $id ),
			'font_family_settings' => is_array( $data['font_family_settings'] ?? null ) ? $data['font_family_settings'] : array(),
			'font_faces'           => is_array( $data['font_faces'] ?? null ) ? $data['font_faces'] : array(),
			'theme_json_version'   => (int) ( $data['theme_json_version'] ?? 0 ),
		);
	}
}
