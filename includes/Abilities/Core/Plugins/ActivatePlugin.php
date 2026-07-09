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
 * T2 destructive write ability: `og-plugins/activate-plugin`.
 *
 * Wraps `POST /wp/v2/plugins/<plugin>` via the Abilities REST Adapter, activating an
 * installed plugin. The fixed `status=active` body param is injected by
 * {@see injectStatus()} after input validation, so the agent only ever sees the
 * `plugin` path capture (the route's raw `status` field is never exposed). This
 * performs site-level activation only; it cannot network-activate, so on multisite a
 * network-only plugin is rejected by core with `rest_network_only_plugin`. Activating
 * a plugin runs its code, so this ability is annotated destructive and is exposed to
 * the browser only when the adapter's write AND destructive settings are both on. The
 * `plugin` input is the plugin file path without the `.php` extension (for example
 * `akismet/akismet`); the route preserves the slash and the plugins controller's
 * `sanitize_plugin_param()` appends `.php`. The output is OVERRIDDEN to the catalog's
 * flat field set through {@see shapeOutput()}. Permission delegates to the route's own
 * check (no `require_permission` floor): the plugins controller requires
 * `activate_plugins` plus the object-level `activate_plugin` capability, the same gate
 * the catalog enforced before, so visibility is unchanged.
 *
 * @since 0.3.0
 */
final class ActivatePlugin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-plugins/activate-plugin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/plugins/(?P<plugin>[^.\/]+(?:\/[^.\/]+)?)',
				'method'          => 'POST',
				'label'           => __( 'Activate Plugin', 'abilities-catalog' ),
				'description'     => __( 'Activates an installed plugin by its file path (site-level activation only; network activation is not supported). Activating a plugin runs its code.', 'abilities-catalog' ),
				'category'        => 'og-core-plugins',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'The plugin file path without the .php extension — the "plugin" value returned by og-plugins/list-plugins, e.g. "akismet/akismet" or "hello" (for hello.php). Not the human-readable plugin name ("Hello Dolly") and not a value ending in ".php".', 'abilities-catalog' ),
							'minLength'   => 1,
							'pattern'     => '^[^./]+(?:/[^./]+)?$',
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectStatus' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'plugin', 'status' ),
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'The plugin file path.', 'abilities-catalog' ),
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'inactive', 'active', 'network-active' ),
							'description' => __( 'The resulting plugin activation status.', 'abilities-catalog' ),
						),
						'name'   => array(
							'type'        => 'string',
							'description' => __( 'The plugin name.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'screen'       => 'plugins.php',
				),
			)
		);
	}

	/**
	 * Injects the fixed `status=active` body param before dispatch.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()`, after
	 * the ability validates input against its schema. The caller never supplies
	 * `status` (it is not in the input schema); pinning it to `active` here is what
	 * turns the plugins controller's status update into an activation. The `plugin`
	 * path capture passes through untouched for the adapter to substitute into the URL.
	 *
	 * @param array<string,mixed> $params The validated ability input (carries `plugin`).
	 * @return array<string,mixed> The input plus the fixed `status` param.
	 */
	public function injectStatus( array $params ): array {
		return $params + array( 'status' => 'active' );
	}

	/**
	 * Flattens the REST plugin body to the catalog's `{plugin, status, name}` set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST plugin body. Each field copies across with a type cast and a safe default,
	 * so a value REST omits comes back as `''` rather than a missing key; `plugin`
	 * falls back to the caller's input value. `$input` is the original ability input
	 * (before `injectStatus()`); `$response` is part of the callback signature but
	 * unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST plugin body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat plugin fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data   = is_array( $data ) ? $data : array();
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		return array(
			'plugin' => (string) ( $data['plugin'] ?? $plugin ),
			'status' => (string) ( $data['status'] ?? '' ),
			'name'   => (string) ( $data['name'] ?? '' ),
		);
	}
}
