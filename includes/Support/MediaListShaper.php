<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `/wp/v2/media` REST items into flat summary rows for the
 * `media/list-media` ability.
 *
 * The media route returns 30+ fields per attachment, including the nested
 * `media_details` (every generated image size — the heaviest part of the
 * payload), `meta`, `class_list`, GMT-duplicate dates, rendered `title`/`caption`
 * objects, and `_links`. Returning that verbatim leaks REST internals and makes a
 * single list call cost thousands of tokens. This shaper maps each item to a
 * small, predictable row (the file bytes and full detail live behind
 * `media/get-media-file`) and exposes the matching `output_schema` fragment so
 * the runtime shape and the declared schema stay in sync.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.7.0
 */
final class MediaListShaper {

	/**
	 * Flat summary row for a media (attachment) REST item.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/media` response.
	 * @return array<string,mixed> The summary row. No `media_details`, `meta`, or `_links`.
	 */
	public static function mediaSummary( array $item ): array {
		return array(
			'id'         => (int) ( $item['id'] ?? 0 ),
			'title'      => self::renderedField( $item['title'] ?? '' ),
			'slug'       => (string) ( $item['slug'] ?? '' ),
			'status'     => (string) ( $item['status'] ?? '' ),
			'mime_type'  => (string) ( $item['mime_type'] ?? '' ),
			'media_type' => (string) ( $item['media_type'] ?? '' ),
			'date'       => (string) ( $item['date'] ?? '' ),
			'author'     => (int) ( $item['author'] ?? 0 ),
			'alt_text'   => (string) ( $item['alt_text'] ?? '' ),
			'caption'    => self::renderedField( $item['caption'] ?? '' ),
			'source_url' => (string) ( $item['source_url'] ?? '' ),
			'link'       => (string) ( $item['link'] ?? '' ),
			'post'       => (int) ( $item['post'] ?? 0 ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::mediaSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function mediaItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'source_url', 'mime_type' ),
			'properties'           => array(
				'id'         => array(
					'type'        => 'integer',
					'description' => __( 'The attachment (media item) ID.', 'abilities-catalog' ),
				),
				'title'      => array(
					'type'        => 'string',
					'description' => __( 'The rendered media title.', 'abilities-catalog' ),
				),
				'slug'       => array(
					'type'        => 'string',
					'description' => __( 'The attachment slug.', 'abilities-catalog' ),
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'The attachment status (usually "inherit").', 'abilities-catalog' ),
				),
				'mime_type'  => array(
					'type'        => 'string',
					'description' => __( 'The MIME type (e.g. "image/png").', 'abilities-catalog' ),
				),
				'media_type' => array(
					'type'        => 'string',
					'description' => __( 'The media type bucket (e.g. "image", "video", "file").', 'abilities-catalog' ),
				),
				'date'       => array(
					'type'        => 'string',
					'description' => __( 'The upload date in site time.', 'abilities-catalog' ),
				),
				'author'     => array(
					'type'        => 'integer',
					'description' => __( 'The uploader user ID.', 'abilities-catalog' ),
				),
				'alt_text'   => array(
					'type'        => 'string',
					'description' => __( 'The image alternative text.', 'abilities-catalog' ),
				),
				'caption'    => array(
					'type'        => 'string',
					'description' => __( 'The rendered caption.', 'abilities-catalog' ),
				),
				'source_url' => array(
					'type'        => 'string',
					'description' => __( 'The direct URL to the original file.', 'abilities-catalog' ),
				),
				'link'       => array(
					'type'        => 'string',
					'description' => __( 'The attachment page permalink.', 'abilities-catalog' ),
				),
				'post'       => array(
					'type'        => 'integer',
					'description' => __( 'The parent post ID the file is attached to (0 when unattached).', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Resolves a field that may be a `['raw' => , 'rendered' => ]` object.
	 *
	 * @param mixed $field The raw field value from the REST item.
	 * @return string The string value.
	 */
	private static function renderedField( $field ): string {
		if ( is_array( $field ) ) {
			return (string) ( $field['rendered'] ?? $field['raw'] ?? '' );
		}

		return (string) $field;
	}
}
