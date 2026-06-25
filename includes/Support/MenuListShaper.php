<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw menu-domain REST items into flat summary rows for the
 * `menus/list-*` abilities.
 *
 * The three menu list routes return heterogeneous, nested rows. Classic menus
 * (`/wp/v2/menus`) carry `_links`, `meta`, `locations`, and `auto_add`; menu
 * items (`/wp/v2/menu-items`) carry a rendered `title` object plus a dozen
 * presentation fields and `_links`; navigation menus (`/wp/v2/navigation`) carry
 * the full serialized block body in `content`, GMT-duplicate dates, `guid`, and
 * `_links`. Returning that verbatim leaks REST internals and breaks the
 * project-wide list-ability rule (each row is a flat, closed summary). This
 * shaper maps each item to a small, predictable row and exposes the matching
 * `output_schema` fragment so the runtime shape and the declared schema stay in
 * sync. The full object lives behind the matching `menus/get-*` ability.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.7.0
 */
final class MenuListShaper {

	/**
	 * Flat summary row for a classic menu (`nav_menu` term) REST item.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/menus` response.
	 * @return array<string,mixed> The summary row. No `_links`, `meta`, `locations`, or `auto_add`.
	 */
	public static function classicMenuSummary( array $item ): array {
		return array(
			'id'          => (int) ( $item['id'] ?? 0 ),
			'name'        => (string) ( $item['name'] ?? '' ),
			'slug'        => (string) ( $item['slug'] ?? '' ),
			'description' => (string) ( $item['description'] ?? '' ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::classicMenuSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function classicMenuItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'name' ),
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'The classic menu term ID.', 'abilities-catalog' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'The menu name.', 'abilities-catalog' ),
				),
				'slug'        => array(
					'type'        => 'string',
					'description' => __( 'The menu slug.', 'abilities-catalog' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The menu description.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a classic menu item (`nav_menu_item` post) REST item.
	 *
	 * Keeps the fields that let an agent read the menu tree (`parent`,
	 * `menu_order`), the link target (`type`, `object`, `object_id`, `url`), and
	 * the owning menu (`menus`). The `menus` field is populated only in the `edit`
	 * context, which `og-menus/list-menu-items` requests by default.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/menu-items` response.
	 * @return array<string,mixed> The summary row. No `_links`, no rendered `title` object.
	 */
	public static function menuItemSummary( array $item ): array {
		return array(
			'id'         => (int) ( $item['id'] ?? 0 ),
			'title'      => self::renderedField( $item['title'] ?? '' ),
			'status'     => (string) ( $item['status'] ?? '' ),
			'type'       => (string) ( $item['type'] ?? '' ),
			'object'     => (string) ( $item['object'] ?? '' ),
			'object_id'  => (int) ( $item['object_id'] ?? 0 ),
			'url'        => (string) ( $item['url'] ?? '' ),
			'menu_order' => (int) ( $item['menu_order'] ?? 0 ),
			'parent'     => (int) ( $item['parent'] ?? 0 ),
			'menus'      => (int) ( $item['menus'] ?? 0 ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::menuItemSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function menuItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'title', 'type', 'menus' ),
			'properties'           => array(
				'id'         => array(
					'type'        => 'integer',
					'description' => __( 'The menu item (post) ID.', 'abilities-catalog' ),
				),
				'title'      => array(
					'type'        => 'string',
					'description' => __( 'The rendered menu item label.', 'abilities-catalog' ),
				),
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'The menu item post status.', 'abilities-catalog' ),
				),
				'type'       => array(
					'type'        => 'string',
					'description' => __( 'The menu item type (e.g. "custom", "post_type", "taxonomy").', 'abilities-catalog' ),
				),
				'object'     => array(
					'type'        => 'string',
					'description' => __( 'The kind of linked object (e.g. "page", "category", "custom").', 'abilities-catalog' ),
				),
				'object_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the linked object (0 for a custom link).', 'abilities-catalog' ),
				),
				'url'        => array(
					'type'        => 'string',
					'description' => __( 'The resolved link URL.', 'abilities-catalog' ),
				),
				'menu_order' => array(
					'type'        => 'integer',
					'description' => __( 'The order of the item within its menu.', 'abilities-catalog' ),
				),
				'parent'     => array(
					'type'        => 'integer',
					'description' => __( 'The parent menu item ID (0 when top level).', 'abilities-catalog' ),
				),
				'menus'      => array(
					'type'        => 'integer',
					'description' => __( 'The classic menu (term) ID this item belongs to. Populated in the edit context.', 'abilities-catalog' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Flat summary row for a block-based navigation menu (`wp_navigation` post).
	 *
	 * Drops the serialized block body (`content`) for parity with the post list
	 * rows; the full body lives behind `og-menus/get-navigation`.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/navigation` response.
	 * @return array<string,mixed> The summary row. No `content`, no `_links`, no GMT dates.
	 */
	public static function navigationSummary( array $item ): array {
		$id = (int) ( $item['id'] ?? 0 );

		return array(
			'id'        => $id,
			'title'     => self::renderedField( $item['title'] ?? '' ),
			'status'    => (string) ( $item['status'] ?? '' ),
			'slug'      => (string) ( $item['slug'] ?? '' ),
			'link'      => (string) ( $item['link'] ?? '' ),
			'date'      => (string) ( $item['date'] ?? '' ),
			'modified'  => (string) ( $item['modified'] ?? '' ),
			'edit_link' => $id > 0 ? (string) get_edit_post_link( $id, 'raw' ) : '',
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::navigationSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function navigationItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'title', 'status' ),
			'properties'           => array(
				'id'        => array(
					'type'        => 'integer',
					'description' => __( 'The navigation menu (post) ID.', 'abilities-catalog' ),
				),
				'title'     => array(
					'type'        => 'string',
					'description' => __( 'The navigation menu title.', 'abilities-catalog' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'The navigation menu post status.', 'abilities-catalog' ),
				),
				'slug'      => array(
					'type'        => 'string',
					'description' => __( 'The navigation menu slug.', 'abilities-catalog' ),
				),
				'link'      => array(
					'type'        => 'string',
					'description' => __( 'The public permalink.', 'abilities-catalog' ),
				),
				'date'      => array(
					'type'        => 'string',
					'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
				),
				'modified'  => array(
					'type'        => 'string',
					'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
				),
				'edit_link' => array(
					'type'        => 'string',
					'description' => __( 'The site-editor URL for editing the navigation menu. Use og-menus/get-navigation for the block content.', 'abilities-catalog' ),
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
