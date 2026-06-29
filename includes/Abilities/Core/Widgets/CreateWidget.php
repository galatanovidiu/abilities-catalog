<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-widgets/create-widget`.
 *
 * Wraps `POST /wp/v2/widgets` via the Abilities REST Adapter to add a widget
 * instance to a sidebar (widget area), or — when `sidebar` is omitted — to the
 * `wp_inactive_widgets` holding area (the route schema's default). The input
 * schema is OVERRIDDEN to a closed set (`id_base`/`sidebar`/`instance`/`form_data`,
 * `id_base` required) so the route's raw POST fields stay hidden. The output is
 * OVERRIDDEN to the catalog's flat `{ created, id, id_base, sidebar, rendered }`
 * row through {@see shapeOutput()} (the shared widget projection plus a `created`
 * success flag).
 *
 * Widget settings travel via EITHER `instance` (an object) OR `form_data` (a
 * URL-encoded string for classic widgets). For a widget type whose object sets
 * `show_instance_in_rest` — the core `block` widget (`WP_Widget_Block`) does —
 * pass `instance.raw` directly, e.g. `{ raw: { content: '<block markup>' } }`;
 * this avoids the HMAC `hash` the `{ encoded, hash }` form requires. The wrapped
 * route's `save_widget()` validates the encoding, the `id_base`, and the
 * `instance` shape, returning `rest_invalid_widget` (400) on a bad value — so
 * that surfaces as the route's specific error, not a permission collapse.
 *
 * Permission delegates to the route's own check (no `require_permission` floor is
 * set): the widgets routes enforce `current_user_can('edit_theme_options')` in
 * `permissions_check` (`class-wp-rest-widgets-controller.php`), the same cap the
 * catalog used, so visibility is unchanged. The route is the hard guard for the
 * create; this ability does no object-level pre-check.
 *
 * Adding a widget edits only the site's front-end appearance and is reversible
 * via `og-widgets/delete-widget`, so it is `destructive:false` and NOT `dangerous`.
 *
 * @since 0.5.0
 */
final class CreateWidget implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/create-widget';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/widgets',
				'method'          => 'POST',
				'label'           => __( 'Create Widget', 'abilities-catalog' ),
				'description'     => __( 'Adds a widget of a given type to a sidebar (widget area), returning the new widget id, its id_base, the sidebar it landed in, and its rendered HTML. Set id_base to a widget type slug from og-widgets/list-widget-types (e.g. "block", "text"), and sidebar to a sidebar id from og-widgets/list-sidebars; omit sidebar to stage the widget inactive (it defaults to the wp_inactive_widgets holding area). Supply widget settings through either instance (an object — for the core "block" widget use { raw: { content: "<block markup>" } }) or form_data (a URL-encoded string for classic widgets), but not both. Reversible via og-widgets/delete-widget.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id_base'   => array(
							'type'        => 'string',
							'description' => __( 'The widget type slug, used as the id_base — discover valid values with og-widgets/list-widget-types (e.g. "block", "text"). Not a widget instance id like "block-3".', 'abilities-catalog' ),
						),
						'sidebar'   => array(
							'type'        => 'string',
							'default'     => 'wp_inactive_widgets',
							'description' => __( 'The target sidebar id from og-widgets/list-sidebars (e.g. "sidebar-1"). Omit to stage the widget in the wp_inactive_widgets holding area (inactive, not shown on the front end).', 'abilities-catalog' ),
						),
						'instance'  => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'Widget-specific settings. For the core "block" widget pass { raw: { content: "<block markup>" } }. For other types that support raw instances use the same { raw: {...} } shape; the { encoded, hash } form needs a server-computed HMAC hash. Provide instance OR form_data, not both.', 'abilities-catalog' ),
						),
						'form_data' => array(
							'type'        => 'string',
							'description' => __( 'URL-encoded admin-form data, for classic widgets that do not accept a raw instance. Provide instance OR form_data, not both.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id_base' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'created', 'id', 'id_base', 'sidebar' ),
					'properties'           => array(
						'created'  => array(
							'type'        => 'boolean',
							'description' => __( 'True when the widget was created. The success signal for this ability.', 'abilities-catalog' ),
						),
						'id'       => array(
							'type'        => 'string',
							'description' => __( 'The new widget instance id (e.g. "block-3"). Use it with og-widgets/get-widget, og-widgets/update-widget, or og-widgets/delete-widget.', 'abilities-catalog' ),
						),
						'id_base'  => array(
							'type'        => 'string',
							'description' => __( 'The widget type slug of the created widget (e.g. "block").', 'abilities-catalog' ),
						),
						'sidebar'  => array(
							'type'        => 'string',
							'description' => __( 'The sidebar id the widget was placed in ("wp_inactive_widgets" when staged inactive).', 'abilities-catalog' ),
						),
						'rendered' => array(
							'type'        => 'string',
							'description' => __( 'The widget\'s rendered front-end HTML, or an empty string when the widget is inactive (in wp_inactive_widgets).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'widgets.php',
				),
			)
		);
	}

	/**
	 * Projects the created REST widget body to the catalog's flat success row.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST widget body. The `created` flag is a constant success signal; the other
	 * fields copy across with a string cast and a safe default, so a value REST
	 * omits comes back as `''` rather than a missing key. `$input` and `$response`
	 * are part of the callback signature but unused here — the body carries
	 * everything this shape needs.
	 *
	 * @param mixed               $data     The REST widget body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat created-widget projection.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'created'  => true,
			'id'       => (string) ( $data['id'] ?? '' ),
			'id_base'  => (string) ( $data['id_base'] ?? '' ),
			'sidebar'  => (string) ( $data['sidebar'] ?? '' ),
			'rendered' => (string) ( $data['rendered'] ?? '' ),
		);
	}
}
