<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `fonts/install-font-family`.
 *
 * Creates a `wp_font_family` post by wrapping `POST /wp/v2/font-families` via
 * `rest_do_request()`. The REST route expects a single `font_family_settings`
 * parameter holding a JSON-encoded string (the multipart/form-data convention)
 * of the theme.json font-family object; this ability builds that string from
 * flat inputs and surfaces the new family's id, slug, and name.
 *
 * Scope: this pass installs the font-family METADATA only (`name`, `fontFamily`,
 * `slug`). Font-face file uploads are deferred — the controller's create args
 * accept `font_family_settings` as a string with no file params yet, and adding
 * faces needs the per-face `POST /wp/v2/font-families/<id>/font-faces` route with
 * multipart `file_params`. A later pass should send faces as base64 inline with a
 * size cap (NO `source_url`).
 *
 * Write annotations (`readonly:false, destructive:false, idempotent:false`) route
 * the `/run` call as POST. The `permission_callback` mirrors the controller's
 * create check exactly: the `wp_font_family` post type maps `create_posts` to
 * `edit_theme_options`, so installing a family requires `edit_theme_options`. The
 * REST route re-checks the capability and validates/sanitizes the settings.
 *
 * @since 0.2.0
 */
final class InstallFontFamily implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'fonts/install-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Install Font Family', 'abilities-catalog' ),
			'description'         => __( 'Installs a new font family (metadata only: name, CSS font-family value, and slug). Does not upload font face files.', 'abilities-catalog' ),
			'category'            => 'fonts',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'        => array(
						'type'        => 'string',
						'description' => __( 'Display name of the font family preset.', 'abilities-catalog' ),
					),
					'font_family' => array(
						'type'        => 'string',
						'description' => __( 'The CSS font-family value (e.g. "Inter", sans-serif).', 'abilities-catalog' ),
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => __( 'Kebab-case unique identifier for the font family. Defaults to a slug derived from the name.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'name', 'font_family' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The new font family post ID.', 'abilities-catalog' ),
					),
					'slug' => array(
						'type'        => 'string',
						'description' => __( 'The resulting font family slug.', 'abilities-catalog' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The resulting font family name.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the REST controller's create check.
	 *
	 * The `wp_font_family` post type maps the `create_posts` capability to
	 * `edit_theme_options`, so installing a font family requires that capability.
	 * The REST route re-checks it (defense in depth).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may install a font family.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * Builds the controller's `font_family_settings` string (JSON-encoded
	 * theme.json font-family object) from the flat inputs and dispatches it.
	 * The REST route validates and sanitizes the settings.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new family's id, slug, name, or the REST error.
	 */
	public function execute( $input ) {
		$input       = is_array( $input ) ? $input : array();
		$name        = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
		$font_family = isset( $input['font_family'] ) ? (string) $input['font_family'] : '';
		$slug        = isset( $input['slug'] ) && '' !== $input['slug']
			? sanitize_title( (string) $input['slug'] )
			: sanitize_title( $name );

		$settings = wp_json_encode(
			array(
				'name'       => $name,
				'fontFamily' => $font_family,
				'slug'       => $slug,
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/font-families' );
		$request->set_param( 'font_family_settings', $settings );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data         = rest_get_server()->response_to_data( $response, false );
		$out_settings = is_array( $data['font_family_settings'] ?? null ) ? $data['font_family_settings'] : array();

		return array(
			'id'   => (int) ( $data['id'] ?? 0 ),
			'slug' => (string) ( $out_settings['slug'] ?? $slug ),
			'name' => (string) ( $out_settings['name'] ?? $name ),
		);
	}
}
