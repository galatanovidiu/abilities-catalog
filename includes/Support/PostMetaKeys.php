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
 * `content/delete-post-meta`, `content/list-post-meta-keys`) only operate on meta
 * keys that core exposes through a post's REST `meta` field. This class mirrors
 * {@see \WP_REST_Meta_Fields::get_registered_fields()} so the abilities see exactly
 * the same set the REST API does and never read or write arbitrary or internal meta:
 *
 * - It merges the object-wide (empty-subtype) registered-meta bucket with the
 *   post-type subtype bucket, like core (class-wp-rest-meta-fields.php:454-457).
 * - It applies the `custom-fields` post-type-support gate, so meta is exposed only
 *   when the post type supports custom fields, matching the posts controller
 *   (class-wp-rest-posts-controller.php:2589-2593, 2742-2743).
 * - It honors the `show_in_rest['name']` alias: a key is keyed by its public REST
 *   name, while its underlying storage key is kept for `get_post_meta()` and the
 *   per-key capability check (class-wp-rest-meta-fields.php:472, 486-487).
 *
 * Each shape also carries a JSON schema so {@see self::castForResponse()} can cast a
 * stored value back to its declared type the way core's `prepare_value()` does
 * (class-wp-rest-meta-fields.php:537, 560-572).
 *
 * @since 0.5.0
 *
 * @phpstan-type MetaShape array{
 *     storage_key: string,
 *     single: bool,
 *     type: string,
 *     description: string,
 *     schema: array<string,mixed>
 * }
 */
final class PostMetaKeys {

	/**
	 * Returns the registered REST-visible meta keys for a post type.
	 *
	 * The returned map is keyed by the public REST name (the `show_in_rest['name']`
	 * alias when set, otherwise the storage key). Each entry keeps the underlying
	 * storage key so callers can read/write the actual meta and run the per-key
	 * capability check against it, matching core.
	 *
	 * @param string $post_type The post type slug.
	 * @return array<string,MetaShape> Map of public REST name to its shape. Empty when
	 *               the post type does not support custom fields or registers no
	 *               REST-visible meta.
	 */
	public static function forPostType( string $post_type ): array {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}

		// Gate (b): core only exposes the `meta` field when the post type supports
		// custom fields (post/page do; attachment does not).
		if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
			return array();
		}

		// Gate (a): merge the object-wide (empty-subtype) bucket with the subtype
		// bucket, exactly like WP_REST_Meta_Fields::get_registered_fields().
		$registered = (array) get_registered_meta_keys( 'post' );
		if ( '' !== $post_type ) {
			$registered = array_merge( $registered, (array) get_registered_meta_keys( 'post', $post_type ) );
		}

		$allowed = array();

		foreach ( $registered as $storage_key => $args ) {
			if ( ! is_string( $storage_key ) || '' === $storage_key ) {
				continue;
			}

			$show_in_rest = $args['show_in_rest'] ?? false;
			if ( empty( $show_in_rest ) ) {
				continue;
			}

			$rest_args = is_array( $show_in_rest ) ? $show_in_rest : array();

			// Gate (c): the public REST name is the alias when provided, else the
			// storage key.
			$name = isset( $rest_args['name'] ) && is_string( $rest_args['name'] ) && '' !== $rest_args['name']
				? $rest_args['name']
				: $storage_key;

			$single = ! empty( $args['single'] );
			$type   = isset( $args['type'] ) && '' !== (string) $args['type'] ? (string) $args['type'] : 'string';

			// Single-value schema: the registered type, allowing a
			// `show_in_rest['schema']` override the way core does.
			$single_schema = array( 'type' => $type );
			if ( isset( $rest_args['schema'] ) && is_array( $rest_args['schema'] ) ) {
				$single_schema = array_merge( $single_schema, $rest_args['schema'] );
			}

			$allowed[ $name ] = array(
				'storage_key' => $storage_key,
				'single'      => $single,
				'type'        => isset( $single_schema['type'] ) ? (string) $single_schema['type'] : $type,
				'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
				'schema'      => $single_schema,
			);
		}

		return $allowed;
	}

	/**
	 * Casts a stored meta value to its declared JSON type for output.
	 *
	 * Mirrors {@see \WP_REST_Meta_Fields::prepare_value()}: a single value is cast
	 * against the key's schema, and each element of a list value is cast against the
	 * same schema. A value that fails schema validation is returned as `null`, like
	 * core. This turns a boolean stored as `"1"` into `true`, an integer stored as
	 * `"42"` into `42`, and so on.
	 *
	 * @param mixed                  $value The stored value: a single value when
	 *                                      `single` is true, otherwise a list.
	 * @param array<string,mixed>    $shape The shape from {@see self::forPostType()}.
	 * @return mixed The cast value, or `null` when a single value fails validation.
	 */
	public static function castForResponse( $value, array $shape ) {
		$schema = isset( $shape['schema'] ) && is_array( $shape['schema'] ) ? $shape['schema'] : array( 'type' => 'string' );

		if ( ! empty( $shape['single'] ) ) {
			return self::castScalar( $value, $schema );
		}

		$values = is_array( $value ) ? $value : array();
		$out    = array();
		foreach ( $values as $item ) {
			$out[] = self::castScalar( $item, $schema );
		}

		return $out;
	}

	/**
	 * Casts one scalar value against a schema, returning null on validation failure.
	 *
	 * @param mixed               $value  The value to cast.
	 * @param array<string,mixed> $schema The JSON schema to cast against.
	 * @return mixed The cast value, or null when validation fails.
	 */
	private static function castScalar( $value, array $schema ) {
		$type = isset( $schema['type'] ) ? (string) $schema['type'] : 'string';

		// Core treats an empty string for a numeric/boolean type as the type's empty
		// value before validating (class-wp-rest-meta-fields.php:563-565).
		if ( '' === $value && in_array( $type, array( 'boolean', 'integer', 'number' ), true ) ) {
			$value = self::emptyValueForType( $type );
		}

		if ( is_wp_error( rest_validate_value_from_schema( $value, $schema ) ) ) {
			return null;
		}

		return rest_sanitize_value_from_schema( $value, $schema );
	}

	/**
	 * Returns the empty value for a scalar type, matching core's
	 * `WP_REST_Meta_Fields::get_empty_value_for_type()`.
	 *
	 * @param string $type The JSON schema type.
	 * @return mixed The empty value for the type.
	 */
	private static function emptyValueForType( string $type ) {
		switch ( $type ) {
			case 'boolean':
				return false;
			case 'integer':
				return 0;
			case 'number':
				return 0.0;
			default:
				return '';
		}
	}
}
