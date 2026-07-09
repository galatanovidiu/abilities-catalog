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
 * T2 non-destructive write ability: `og-fonts/install-font-family`.
 *
 * Creates a `wp_font_family` post by wrapping `POST /wp/v2/font-families` via the
 * Abilities REST Adapter. The REST route expects a single `font_family_settings`
 * parameter holding a JSON-encoded string (the multipart/form-data convention) of
 * the theme.json font-family object. The catalog keeps a flat input schema
 * (`name`, `font_family`, `slug`) — REQUIRED so the derived schema does NOT expose
 * the raw `font_family_settings` string — and {@see buildSettings()} packs the flat
 * inputs into that one parameter at dispatch. The output is OVERRIDDEN to the
 * catalog's flat field set through {@see shapeOutput()}.
 *
 * Scope: this pass installs the font-family METADATA only (`name`, `fontFamily`,
 * `slug`). Font-face file uploads are deferred — the controller's create args
 * accept `font_family_settings` as a string with no file params yet, and adding
 * faces needs the per-face `POST /wp/v2/font-families/<id>/font-faces` route with
 * multipart `file_params`. A later pass should send faces as base64 inline with a
 * size cap (NO `source_url`).
 *
 * Permission delegates to the route's own create check (no `require_permission`
 * floor): the `wp_font_family` post type maps `create_posts` to
 * `edit_theme_options`, so installing a family requires `edit_theme_options`. The
 * write annotations (`readonly:false, destructive:false, idempotent:false`) are
 * declared under `meta.annotations`, as the adapter requires for a write.
 *
 * @since 0.2.0
 */
final class InstallFontFamily implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-fonts/install-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/font-families',
				'method'          => 'POST',
				'label'           => __( 'Install Font Family', 'abilities-catalog' ),
				'description'     => __( 'Installs a new font family (metadata only: name, CSS font-family value, and slug). Does not upload font face files.', 'abilities-catalog' ),
				'category'        => 'og-core-fonts',
				'input_schema'    => array(
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
				'input_callback'  => array( $this, 'buildSettings' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The new font family post ID.', 'abilities-catalog' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => __( 'The resulting font family slug.', 'abilities-catalog' ),
						),
						'name'        => array(
							'type'        => 'string',
							'description' => __( 'The resulting font family name.', 'abilities-catalog' ),
						),
						'font_family' => array(
							'type'        => 'string',
							'description' => __( 'The stored CSS font-family value, as sanitized by WordPress.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'       => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'abilities_catalog' => array(
						'scope' => 'site',
					),
					'show_in_rest'      => true,
					'screen'            => 'font-library.php',
				),
			)
		);
	}

	/**
	 * Packs the flat inputs into the route's single `font_family_settings` param.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()`, at
	 * dispatch, after the input is validated against the flat schema. It mirrors the
	 * old pre-dispatch work: `name` is sanitized as text, `slug` defaults to a slug
	 * derived from the name when omitted, and `font_family` is passed raw (the route
	 * validates and sanitizes the settings). It drops the flat keys and emits ONLY
	 * the route's `font_family_settings` parameter (a JSON-encoded theme.json
	 * font-family object), the shape the controller expects. Deterministic and pure.
	 *
	 * @param array<string,mixed> $params The validated flat input.
	 * @return array<string,mixed> The single `font_family_settings` route param.
	 */
	public function buildSettings( array $params ): array {
		$name        = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$font_family = isset( $params['font_family'] ) ? (string) $params['font_family'] : '';
		$slug        = isset( $params['slug'] ) && '' !== $params['slug']
			? sanitize_title( (string) $params['slug'] )
			: sanitize_title( $name );

		$settings = wp_json_encode(
			array(
				'name'       => $name,
				'fontFamily' => $font_family,
				'slug'       => $slug,
			)
		);

		return array(
			'font_family_settings' => $settings,
		);
	}

	/**
	 * Flattens the created REST font-family body to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST response body. The created family's settings live under the body's
	 * `font_family_settings` key (an object on read-back); `id` comes from the body
	 * top level. `$input` and `$response` are part of the callback signature but
	 * unused — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST font-family body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat font-family fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data         = is_array( $data ) ? $data : array();
		$out_settings = is_array( $data['font_family_settings'] ?? null ) ? $data['font_family_settings'] : array();

		return array(
			'id'          => (int) ( $data['id'] ?? 0 ),
			'slug'        => (string) ( $out_settings['slug'] ?? '' ),
			'name'        => (string) ( $out_settings['name'] ?? '' ),
			'font_family' => (string) ( $out_settings['fontFamily'] ?? '' ),
		);
	}
}
