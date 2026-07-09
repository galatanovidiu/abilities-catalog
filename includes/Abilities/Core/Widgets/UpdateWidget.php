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
 * Write ability: `og-widgets/update-widget`.
 *
 * Wraps `POST /wp/v2/widgets/<id>` via the Abilities REST Adapter (the route
 * registers update under the EDITABLE methods). The input schema is OVERRIDDEN to
 * the catalog's narrow surface — `id` (the required path capture) plus `instance`,
 * `form_data`, and `sidebar` — so raw REST fields stay hidden. The adapter
 * forwards exactly the supplied keys (no schema defaults injected), so an omitted
 * field leaves the widget unchanged, preserving the old key-presence intent
 * without an `input_callback`. The output is OVERRIDDEN to the catalog's flat
 * field set via {@see shapeOutput()}. Permission delegates to the route's own
 * check (no `require_permission` floor) — the route enforces `edit_theme_options`,
 * the same capability the catalog gated on, so this ability does not widen access.
 *
 * @since 0.4.0
 */
final class UpdateWidget implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/update-widget';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/widgets/(?P<id>[\w\-]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Widget', 'abilities-catalog' ),
				'description'     => __( 'Updates a widget\'s settings and/or moves it to another sidebar (widget area), returning the widget\'s id, id_base, sidebar, and rendered HTML. Identify the widget by its instance id from og-widgets/list-widgets; discover sidebar ids with og-widgets/list-sidebars. Omitted fields are left unchanged. Pass settings as either instance (an object; for the core "block" widget use {raw:{content}} with Gutenberg block markup) or form_data (a URL-encoded string, for classic widgets). Pass sidebar to move the widget to that sidebar. Reversible by updating it again.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'        => array(
							'type'        => 'string',
							'description' => __( 'The widget instance id to update, e.g. "block-3" or "text-2". Discover it with og-widgets/list-widgets.', 'abilities-catalog' ),
						),
						'instance'  => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'description'          => __( 'New widget settings. Shape is widget-specific; for the core "block" widget use {raw:{content:"<block markup>"}}. Provide instance OR form_data, not both. Omit to leave settings unchanged.', 'abilities-catalog' ),
						),
						'form_data' => array(
							'type'        => 'string',
							'description' => __( 'URL-encoded admin-form data, for classic widgets that do not accept a raw instance. Provide instance OR form_data, not both. Omit to leave settings unchanged.', 'abilities-catalog' ),
						),
						'sidebar'   => array(
							'type'        => 'string',
							'description' => __( 'Move the widget to this sidebar id (from og-widgets/list-sidebars). Use "wp_inactive_widgets" to deactivate it. Omit to leave the widget in its current sidebar.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'updated', 'id', 'id_base', 'sidebar' ),
					'properties'           => array(
						'updated'  => array(
							'type'        => 'boolean',
							'description' => __( 'Always true when the update succeeded.', 'abilities-catalog' ),
						),
						'id'       => array(
							'type'        => 'string',
							'description' => __( 'The widget instance id.', 'abilities-catalog' ),
						),
						'id_base'  => array(
							'type'        => 'string',
							'description' => __( 'The widget type slug (e.g. "block", "text").', 'abilities-catalog' ),
						),
						'sidebar'  => array(
							'type'        => 'string',
							'description' => __( 'The sidebar the widget now belongs to ("wp_inactive_widgets" when inactive).', 'abilities-catalog' ),
						),
						'rendered' => array(
							'type'        => 'string',
							'description' => __( 'The widget\'s front-end HTML output, or an empty string when the widget is inactive.', 'abilities-catalog' ),
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
	 * Flattens the REST widget body to the catalog's five-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST widget body. `updated` is the constant `true` (the dispatch succeeded);
	 * the other fields copy across with a type cast and a safe default. `$input` and
	 * `$response` are part of the callback signature but unused here — the body
	 * carries everything this shape needs, and the original input's `id` need not be
	 * echoed because the route returns the canonical `id`.
	 *
	 * @param mixed               $data     The REST widget body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat widget fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'updated'  => true,
			'id'       => (string) ( $data['id'] ?? '' ),
			'id_base'  => (string) ( $data['id_base'] ?? '' ),
			'sidebar'  => (string) ( $data['sidebar'] ?? '' ),
			'rendered' => (string) ( $data['rendered'] ?? '' ),
		);
	}
}
