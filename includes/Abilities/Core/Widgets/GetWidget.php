<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Widgets;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-widgets/get-widget`.
 *
 * Wraps `GET /wp/v2/widgets/<id>` via `rest_do_request()` and projects the
 * response into the shared flat widget row (`id`, `id_base`, `sidebar`,
 * `rendered`). The admin-form HTML (`rendered_form`) and the encoded `instance`
 * object are dropped: they are noise for an agent and the `instance` carries an
 * HMAC hash. Read-only; the wrapped route owns object existence and per-sidebar
 * read visibility underneath.
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
		return array(
			'label'               => __( 'Get Widget', 'abilities-catalog' ),
			'description'         => __( 'Returns a single widget instance by id, including its widget type (id_base), the sidebar it sits in, and its rendered front-end HTML. Discover ids with og-widgets/list-widgets.', 'abilities-catalog' ),
			'category'            => 'og-core-widgets',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'string',
						'description' => __( 'The widget instance id, e.g. "block-3" or "text-2". Discover ids with og-widgets/list-widgets.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: baseline `edit_theme_options` to read a widget.
	 *
	 * Coarse, object-independent gate matching every widgets/sidebars route's
	 * own `permission_callback` (`current_user_can('edit_theme_options')`), so it
	 * is never weaker than core. Object existence is deferred to the wrapped
	 * route: doing the lookup here would mask a missing id as "permission denied",
	 * whereas the route surfaces its specific `rest_widget_not_found` 404 via
	 * RestError::from().
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read widgets.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat widget row, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// Concatenate the id so a slash in the route survives; never set_param.
		$request = new WP_REST_Request( 'GET', '/wp/v2/widgets/' . $id );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'       => (string) ( $data['id'] ?? $id ),
			'id_base'  => (string) ( $data['id_base'] ?? '' ),
			'sidebar'  => (string) ( $data['sidebar'] ?? '' ),
			'rendered' => (string) ( $data['rendered'] ?? '' ),
		);
	}
}
