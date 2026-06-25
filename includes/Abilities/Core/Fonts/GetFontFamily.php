<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-fonts/get-font-family`.
 *
 * Wraps `GET /wp/v2/font-families/<id>` via `rest_do_request()` and shapes the
 * response into a flat field set. Read-only; requires `edit_theme_options`.
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
		return array(
			'label'               => __( 'Get Font Family', 'abilities-catalog' ),
			'description'         => __( 'Returns a single installed font family by ID, including its settings and font faces.', 'abilities-catalog' ),
			'category'            => 'og-core-fonts',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: requires the theme-options capability (catalog).
	 *
	 * Returns false when no font family ID was supplied.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the font family.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat font-family fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/font-families/' . $id );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'                   => (int) ( $data['id'] ?? $id ),
			'font_family_settings' => is_array( $data['font_family_settings'] ?? null ) ? $data['font_family_settings'] : array(),
			'font_faces'           => is_array( $data['font_faces'] ?? null ) ? $data['font_faces'] : array(),
			'theme_json_version'   => (int) ( $data['theme_json_version'] ?? 0 ),
		);
	}
}
