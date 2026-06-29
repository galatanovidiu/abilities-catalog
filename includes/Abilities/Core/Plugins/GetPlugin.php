<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-plugins/get-plugin`.
 *
 * Wraps `GET /wp/v2/plugins/<plugin>` via the Abilities REST Adapter. The input
 * schema is OVERRIDDEN to the single required `plugin` string (the path capture),
 * so the caller supplies the plugin file path without the `.php` extension (for
 * example `akismet/akismet`); the capture sub-pattern `[^.\/]+(?:\/[^.\/]+)?`
 * matches a slash, so the adapter leaves the slash raw rather than URL-encoding it.
 * The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}. Permission delegates to the route's own check
 * (`activate_plugins`); no `require_permission` floor is set, since the route's
 * capability already matches the catalog's.
 *
 * CAVEAT: the route capture excludes a dot, so a `.php`-suffixed value 404s (same
 * as before conversion).
 *
 * @since 0.1.0
 */
final class GetPlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-plugins/get-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/plugins/(?P<plugin>[^.\/]+(?:\/[^.\/]+)?)',
				'method'          => 'GET',
				'label'           => __( 'Get Plugin', 'abilities-catalog' ),
				'description'     => __( 'Returns details about a single installed plugin by its file path.', 'abilities-catalog' ),
				'category'        => 'og-core-plugins',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'pattern'     => '^[^./]+(?:/[^./]+)?$',
							'description' => __( 'The plugin file path without the .php extension, for example "akismet/akismet".', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'plugin', 'status' ),
					'properties'           => array(
						'plugin'       => array(
							'type'        => 'string',
							'description' => __( 'The plugin file path without the .php extension.', 'abilities-catalog' ),
						),
						'status'       => array(
							'type'        => 'string',
							'enum'        => array( 'inactive', 'active', 'network-active' ),
							'description' => __( 'The plugin activation status.', 'abilities-catalog' ),
						),
						'name'         => array(
							'type'        => 'string',
							'description' => __( 'The plugin name.', 'abilities-catalog' ),
						),
						'version'      => array(
							'type'        => 'string',
							'description' => __( 'The plugin version.', 'abilities-catalog' ),
						),
						'description'  => array(
							'type'        => 'string',
							'description' => __( 'The plugin description.', 'abilities-catalog' ),
						),
						'author'       => array(
							'type'        => 'string',
							'description' => __( 'The plugin author.', 'abilities-catalog' ),
						),
						'plugin_uri'   => array(
							'type'        => 'string',
							'description' => __( 'The plugin home page URL.', 'abilities-catalog' ),
						),
						'network_only' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the plugin can only be activated network-wide.', 'abilities-catalog' ),
						),
						'requires_wp'  => array(
							'type'        => 'string',
							'description' => __( 'The minimum required WordPress version.', 'abilities-catalog' ),
						),
						'requires_php' => array(
							'type'        => 'string',
							'description' => __( 'The minimum required PHP version.', 'abilities-catalog' ),
						),
						'textdomain'   => array(
							'type'        => 'string',
							'description' => __( 'The plugin text domain.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Flattens the REST plugin body to the catalog's 11-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST plugin body. Each field copies across with a type cast and a safe default;
	 * `name`, `description`, and `author` may arrive as a `raw`/`rendered` array and
	 * are normalized to a string via {@see coerceString()}. `$input` and `$response`
	 * are part of the callback signature but unused — the body carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The REST plugin body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat plugin fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'plugin'       => (string) ( $data['plugin'] ?? '' ),
			'status'       => (string) ( $data['status'] ?? '' ),
			'name'         => $this->coerceString( $data['name'] ?? '' ),
			'version'      => (string) ( $data['version'] ?? '' ),
			'description'  => $this->coerceString( $data['description'] ?? '' ),
			'author'       => $this->coerceString( $data['author'] ?? '' ),
			'plugin_uri'   => (string) ( $data['plugin_uri'] ?? '' ),
			'network_only' => (bool) ( $data['network_only'] ?? false ),
			'requires_wp'  => (string) ( $data['requires_wp'] ?? '' ),
			'requires_php' => (string) ( $data['requires_php'] ?? '' ),
			'textdomain'   => (string) ( $data['textdomain'] ?? '' ),
		);
	}

	/**
	 * Coerces a REST field that may be a string or a `raw`/`rendered` array to a string.
	 *
	 * @param mixed $value The raw field value.
	 * @return string The string value.
	 */
	private function coerceString( $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['rendered'] ?? ( $value['raw'] ?? '' );
		}

		return (string) $value;
	}
}
