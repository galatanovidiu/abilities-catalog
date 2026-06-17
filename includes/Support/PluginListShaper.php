<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Projects raw `/wp/v2/plugins` REST items into flat summary rows for the
 * `plugins/list-plugins` ability.
 *
 * The plugins route returns each plugin with a rendered `description` object,
 * `author`/`author_uri`/`plugin_uri`, `requires_wp`/`requires_php`, `textdomain`,
 * and `_links`. Returning that verbatim leaks REST internals and breaks the
 * project-wide list-ability rule. This shaper maps each item to a small,
 * predictable row (the full detail lives behind `plugins/get-plugin`) and exposes
 * the matching `output_schema` fragment so the runtime shape and the declared
 * schema stay in sync.
 *
 * This is NOT under `includes/Abilities/`, so the Registry never treats it as an
 * ability.
 *
 * @since 0.7.0
 */
final class PluginListShaper {

	/**
	 * Flat summary row for a plugin REST item.
	 *
	 * @param array<string,mixed> $item A single item from a `/wp/v2/plugins` response.
	 * @return array<string,mixed> The summary row. No rendered `description` object, no `_links`.
	 */
	public static function pluginSummary( array $item ): array {
		return array(
			'plugin'       => (string) ( $item['plugin'] ?? '' ),
			'status'       => (string) ( $item['status'] ?? '' ),
			'name'         => self::renderedField( $item['name'] ?? '' ),
			'version'      => (string) ( $item['version'] ?? '' ),
			'network_only' => (bool) ( $item['network_only'] ?? false ),
		);
	}

	/**
	 * The `output_schema` item definition matching {@see self::pluginSummary()}.
	 *
	 * @return array<string,mixed> A JSON-Schema object fragment.
	 */
	public static function pluginItemSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'plugin', 'status' ),
			'properties'           => array(
				'plugin'       => array(
					'type'        => 'string',
					'description' => __( 'The plugin file path (use plugins/get-plugin for full details).', 'abilities-catalog' ),
				),
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'The plugin activation status ("active", "inactive", or "network-active").', 'abilities-catalog' ),
				),
				'name'         => array(
					'type'        => 'string',
					'description' => __( 'The plugin name.', 'abilities-catalog' ),
				),
				'version'      => array(
					'type'        => 'string',
					'description' => __( 'The plugin version.', 'abilities-catalog' ),
				),
				'network_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the plugin can only be activated network-wide.', 'abilities-catalog' ),
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
