<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `/wp/v2/themes` REST items into flat summary rows for the
 * `themes/list-themes` ability.
 *
 * The themes route returns heterogeneous, nested rows: `_links`, rendered
 * objects (`['raw' => , 'rendered' => ]`) for `name`, `author`, `theme_uri`,
 * `description`, and active-theme-only deep fields such as `theme_supports`.
 * Returning that verbatim leaks REST internals and breaks the project-wide
 * list-ability rule (each row is a flat, closed summary). This shaper maps each
 * item to a small, predictable row and exposes the matching `output_schema`
 * fragment so the runtime shape and the declared schema stay in sync.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.1.0
 */
final class ThemeListShaper {

	/**
	 * Flat summary row for a themes-route REST item.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/themes` response.
	 * @return array<string,mixed> The summary row. No `_links`, no nested rendered objects.
	 */
	public static function themeSummary( array $item ): array {
		return array(
			'stylesheet'     => (string) ( $item['stylesheet'] ?? '' ),
			'template'       => (string) ( $item['template'] ?? '' ),
			'name'           => self::renderedField( $item['name'] ?? '' ),
			'status'         => (string) ( $item['status'] ?? '' ),
			'version'        => (string) ( $item['version'] ?? '' ),
			'is_block_theme' => (bool) ( $item['is_block_theme'] ?? false ),
			'author'         => self::renderedField( $item['author'] ?? '' ),
			'theme_uri'      => self::renderedField( $item['theme_uri'] ?? '' ),
			'description'    => self::renderedField( $item['description'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::themeSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function themeItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'stylesheet', 'name', 'status' ),
			'properties'           => array(
				'stylesheet'     => array(
					'type'        => 'string',
					'description' => __( 'The theme directory name (stylesheet).', 'abilities-catalog' ),
				),
				'template'       => array(
					'type'        => 'string',
					'description' => __( 'The template directory name (the parent theme for a child theme).', 'abilities-catalog' ),
				),
				'name'           => array(
					'type'        => 'string',
					'description' => __( 'The theme display name.', 'abilities-catalog' ),
				),
				'status'         => array(
					'type'        => 'string',
					'description' => __( 'The theme status: "active" or "inactive".', 'abilities-catalog' ),
				),
				'version'        => array(
					'type'        => 'string',
					'description' => __( 'The theme version.', 'abilities-catalog' ),
				),
				'is_block_theme' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the theme is a block theme.', 'abilities-catalog' ),
				),
				'author'         => array(
					'type'        => 'string',
					'description' => __( 'The theme author.', 'abilities-catalog' ),
				),
				'theme_uri'      => array(
					'type'        => 'string',
					'description' => __( 'The theme home page URL.', 'abilities-catalog' ),
				),
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'The theme description.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Resolves a themes-route field that may be a `['rendered' => string]` object.
	 *
	 * @param mixed $field The raw field value from the REST item.
	 * @return string The rendered string value.
	 */
	private static function renderedField( $field ): string {
		if ( is_array( $field ) ) {
			return (string) ( $field['rendered'] ?? '' );
		}

		return (string) $field;
	}
}
