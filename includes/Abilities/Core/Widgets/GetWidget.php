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
 * Read ability: `og-widgets/get-widget`.
 *
 * Wraps `GET /wp/v2/widgets/<id>` via the Abilities REST Adapter. The input
 * schema is DERIVED from the route — the path capture `id` (string, required).
 * The output is OVERRIDDEN to the shared flat widget row (`id`, `id_base`,
 * `sidebar`, `rendered`) through {@see shapeOutput()}, which drops the admin-form
 * HTML (`rendered_form`) and the encoded `instance` object (the latter carries an
 * HMAC hash). Permission delegates to the route's own check (no `require_permission`
 * floor), so the route owns object existence and per-sidebar read visibility.
 *
 * @since 0.1.0
 */
final class GetWidget implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-widgets/get-widget';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/widgets/(?P<id>[\w\-]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Widget', 'abilities-catalog' ),
				'description'     => __( 'Returns a single widget instance by id, including its widget type (id_base), the sidebar it sits in, and its rendered front-end HTML. Discover ids with og-widgets/list-widgets.', 'abilities-catalog' ),
				'category'        => 'og-core-widgets',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'id_base', 'sidebar' ),
					'properties'           => array(
						'id'       => array(
							'type'        => 'string',
							'description' => __( 'The widget instance id, e.g. "block-3".', 'abilities-catalog' ),
						),
						'id_base'  => array(
							'type'        => 'string',
							'description' => __( 'The widget type slug, e.g. "block" or "text".', 'abilities-catalog' ),
						),
						'sidebar'  => array(
							'type'        => 'string',
							'description' => __( 'The id of the sidebar (widget area) the widget sits in, or "wp_inactive_widgets" when it is deactivated.', 'abilities-catalog' ),
						),
						'rendered' => array(
							'type'        => 'string',
							'description' => __( 'The front-end HTML the widget renders. Empty when the widget is inactive (in wp_inactive_widgets).', 'abilities-catalog' ),
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
	 * Projects the REST widget body to the shared flat widget row.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST widget body. The four kept fields copy across with a type cast and a safe
	 * default; `rendered_form` (admin-form HTML) and the encoded `instance` object
	 * (which carries an HMAC hash) are dropped. `$input` and `$response` are part of
	 * the callback signature but unused here — the body carries everything this shape
	 * needs.
	 *
	 * @param mixed               $data     The REST widget body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat widget row.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'       => (string) ( $data['id'] ?? '' ),
			'id_base'  => (string) ( $data['id_base'] ?? '' ),
			'sidebar'  => (string) ( $data['sidebar'] ?? '' ),
			'rendered' => (string) ( $data['rendered'] ?? '' ),
		);
	}
}
