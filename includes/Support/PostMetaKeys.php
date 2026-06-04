<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the registered, REST-visible meta keys for a post type.
 *
 * The post-meta abilities (`content/get-post-meta`, `content/update-post-meta`,
 * `content/delete-post-meta`) only operate on meta keys that core has registered
 * with `show_in_rest` for the post type. This mirrors what the WordPress REST API
 * exposes through a post's `meta` field, so the abilities never read or write
 * arbitrary or internal meta that the site has not opted into exposing. Each
 * ability gates its keys against {@see self::forPostType()} and rejects anything
 * off the list with an actionable error.
 *
 * @since 0.5.0
 */
final class PostMetaKeys {

	/**
	 * Returns the registered `show_in_rest` meta keys for a post type.
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string,array{single:bool,type:string,description:string}> Map of
	 *               meta key to its shape (single value vs. list, declared type,
	 *               and human description). Empty when the post type registers none.
	 */
	public static function forPostType( string $post_type ): array {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}

		$registered = (array) get_registered_meta_keys( 'post', $post_type );
		$allowed    = array();

		foreach ( $registered as $key => $args ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$show_in_rest = $args['show_in_rest'] ?? false;
			if ( empty( $show_in_rest ) ) {
				continue;
			}

			$allowed[ $key ] = array(
				'single'      => ! empty( $args['single'] ),
				'type'        => isset( $args['type'] ) ? (string) $args['type'] : 'string',
				'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			);
		}

		return $allowed;
	}
}
