<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw font-domain REST items into flat summary rows for the
 * `fonts/list-*` abilities.
 *
 * Font collections (`/wp/v2/font-collections`) carry a `font_families` field that
 * can hold the entire remote catalog (hundreds of nested family definitions for
 * the bundled "google-fonts" collection) plus `categories` and `_links` — far too
 * heavy for a discovery list. Font families (`/wp/v2/font-families`) nest their
 * descriptive fields inside a `font_family_settings` object and list their faces
 * as a `font_faces` array. Returning either verbatim leaks REST internals and
 * breaks the project-wide list-ability rule. This shaper maps each item to a
 * small, predictable row (the full family settings and faces live behind
 * `fonts/get-font-family`) and exposes the matching `output_schema` fragment so
 * the runtime shape and the declared schema stay in sync.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.7.0
 */
final class FontListShaper {

	/**
	 * Flat summary row for a font-collection REST item.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/font-collections` response.
	 * @return array<string,mixed> The summary row. No `font_families` catalog, no `categories`, no `_links`.
	 */
	public static function collectionSummary( array $item ): array {
		return array(
			'slug'        => (string) ( $item['slug'] ?? '' ),
			'name'        => (string) ( $item['name'] ?? '' ),
			'description' => (string) ( $item['description'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::collectionSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function collectionItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'slug', 'name' ),
			'properties'           => array(
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'The font collection slug (e.g. "google-fonts").', 'abilities-catalog' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The font collection display name.', 'abilities-catalog' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The font collection description.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a font-family REST item.
	 *
	 * Flattens the descriptive fields out of `font_family_settings` and reduces the
	 * `font_faces` array to a count; the full settings and face IDs live behind
	 * `fonts/get-font-family`.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/font-families` response.
	 * @return array<string,mixed> The summary row. No nested `font_family_settings`, no `_links`.
	 */
	public static function fontFamilySummary( array $item ): array {
		$settings = is_array( $item['font_family_settings'] ?? null ) ? $item['font_family_settings'] : array();
		$faces    = is_array( $item['font_faces'] ?? null ) ? $item['font_faces'] : array();

		return array(
			'id'                 => (int) ( $item['id'] ?? 0 ),
			'name'               => (string) ( $settings['name'] ?? '' ),
			'slug'               => (string) ( $settings['slug'] ?? '' ),
			'font_family'        => (string) ( $settings['fontFamily'] ?? '' ),
			'theme_json_version' => (int) ( $item['theme_json_version'] ?? 0 ),
			'font_faces_count'   => count( $faces ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::fontFamilySummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function fontFamilyItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'name' ),
			'properties'           => array(
				'id'                 => array(
					'type'        => 'integer',
					'description' => __( 'The font family post ID.', 'abilities-catalog' ),
				),
				'name'               => array(
					'type'        => 'string',
					'description' => __( 'The human-readable font family name.', 'abilities-catalog' ),
				),
				'slug'               => array(
					'type'        => 'string',
					'description' => __( 'The font family slug.', 'abilities-catalog' ),
				),
				'font_family'        => array(
					'type'        => 'string',
					'description' => __( 'The CSS font-family value.', 'abilities-catalog' ),
				),
				'theme_json_version' => array(
					'type'        => 'integer',
					'description' => __( 'The theme.json schema version of the family.', 'abilities-catalog' ),
				),
				'font_faces_count'   => array(
					'type'        => 'integer',
					'description' => __( 'The number of font faces in the family. Use fonts/get-font-family for the face IDs.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
