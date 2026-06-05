<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw REST collection items into flat summary rows for list abilities.
 *
 * The content list abilities wrap core REST collection routes and previously
 * returned `rest_get_server()->response_to_data()` verbatim — full `content.raw`
 * bodies, `_links`, `guid`, `class_list`, and GMT-duplicate dates. That leaked
 * REST internals and made a single list call cost thousands of tokens. This
 * helper maps each item to a small, predictable summary (the body lives behind
 * the matching `get-*` ability) and exposes the matching `output_schema`
 * fragment so the shape and the schema stay in sync.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.6.0
 */
final class ContentListShaper {

	/**
	 * Flat summary row for a post-like REST item (post, page, or CPT item).
	 *
	 * @param array<string,mixed> $item A single item from a REST collection response.
	 * @return array<string,mixed> The summary row. No content body, no `_links`.
	 */
	public static function postSummary( array $item ): array {
		$id = (int) ( $item['id'] ?? 0 );

		return array(
			'id'        => $id,
			'title'     => (string) ( $item['title']['rendered'] ?? '' ),
			'status'    => (string) ( $item['status'] ?? '' ),
			'type'      => (string) ( $item['type'] ?? '' ),
			'link'      => (string) ( $item['link'] ?? '' ),
			'edit_link' => (string) get_edit_post_link( $id, 'raw' ),
			'date'      => (string) ( $item['date'] ?? '' ),
			'slug'      => (string) ( $item['slug'] ?? '' ),
			'author'    => (int) ( $item['author'] ?? 0 ),
			'excerpt'   => (string) ( $item['excerpt']['rendered'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::postSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function postItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'title', 'status', 'type', 'link' ),
			'properties'           => array(
				'id'        => array(
					'type'        => 'integer',
					'description' => __( 'The item ID.', 'abilities-catalog' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'The rendered title.', 'abilities-catalog' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'The item status.', 'abilities-catalog' ),
				),
				'type'      => array(
					'type'        => 'string',
					'description' => __( 'The post type slug.', 'abilities-catalog' ),
				),
				'link'      => array(
					'type'        => 'string',
					'description' => __( 'The public permalink.', 'abilities-catalog' ),
				),
				'edit_link' => array(
					'type'        => 'string',
					'description' => __( 'The wp-admin URL to edit the item.', 'abilities-catalog' ),
				),
				'date'      => array(
					'type'        => 'string',
					'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
				),
				'slug'      => array(
					'type'        => 'string',
					'description' => __( 'The item slug.', 'abilities-catalog' ),
				),
				'author'    => array(
					'type'        => 'integer',
					'description' => __( 'The author user ID.', 'abilities-catalog' ),
				),
				'excerpt'   => array(
					'type'        => 'string',
					'description' => __( 'The rendered excerpt. Use the matching get ability for the full content body.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a page REST item.
	 *
	 * Extends {@see self::postSummary()} with the page-specific fields the core
	 * `/wp/v2/pages` route exposes: hierarchy (`parent`), ordering (`menu_order`),
	 * and the assigned page `template`. Lets an agent read the page tree and
	 * ordering without a follow-up `content/get-page` per row.
	 *
	 * @param array<string,mixed> $item A single item from a REST collection response.
	 * @return array<string,mixed> The summary row. No content body, no `_links`.
	 */
	public static function pageSummary( array $item ): array {
		return self::postSummary( $item ) + array(
			'parent'     => (int) ( $item['parent'] ?? 0 ),
			'menu_order' => (int) ( $item['menu_order'] ?? 0 ),
			'template'   => (string) ( $item['template'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::pageSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function pageItemSchema(): array {
		$schema = self::postItemSchema();

		$schema['properties']['parent']     = array(
			'type'        => 'integer',
			'description' => __( 'The parent page ID (0 when top level).', 'abilities-catalog' ),
		);
		$schema['properties']['menu_order'] = array(
			'type'        => 'integer',
			'description' => __( 'The page order value used for sorting.', 'abilities-catalog' ),
		);
		$schema['properties']['template']   = array(
			'type'        => 'string',
			'description' => __( 'The assigned page template file, or empty for the default.', 'abilities-catalog' ),
		);

		return $schema;
	}

	/**
	 * Flat summary row for a post-revision REST item.
	 *
	 * @param array<string,mixed> $item A single revision from a REST collection response.
	 * @return array<string,mixed> The summary row. No content body, no `_links`.
	 */
	public static function revisionSummary( array $item ): array {
		return array(
			'id'     => (int) ( $item['id'] ?? 0 ),
			'parent' => (int) ( $item['parent'] ?? 0 ),
			'author' => (int) ( $item['author'] ?? 0 ),
			'date'   => (string) ( $item['date'] ?? '' ),
			'title'  => (string) ( $item['title']['rendered'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::revisionSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function revisionItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'parent' ),
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'description' => __( 'The revision ID.', 'abilities-catalog' ),
				),
				'parent' => array(
					'type'        => 'integer',
					'description' => __( 'The parent post ID.', 'abilities-catalog' ),
				),
				'author' => array(
					'type'        => 'integer',
					'description' => __( 'The author user ID.', 'abilities-catalog' ),
				),
				'date'   => array(
					'type'        => 'string',
					'description' => __( 'The revision date in site time.', 'abilities-catalog' ),
				),
				'title'  => array(
					'type'        => 'string',
					'description' => __( 'The rendered revision title.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}
}
