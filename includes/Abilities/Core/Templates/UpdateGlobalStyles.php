<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `templates/update-global-styles`.
 *
 * Wraps `POST /wp/v2/global-styles/<id>` via `rest_do_request()`, where `<id>`
 * is the `wp_global_styles` post id for the active theme (resolve it first with
 * `templates/get-global-styles`). The outer ability `/run` call is POST (an
 * update, not a delete); the internal REST verb is also POST (EDITABLE).
 *
 * This is annotated DESTRUCTIVE because it replaces the active theme's global
 * settings and styles (`theme.json`-shaped overrides), changing the appearance
 * of the whole site. The change is recoverable (the override record can be
 * reset) but has a high blast radius. The browser exposes it only when both the
 * adapter write setting and destructive setting are on.
 *
 * The `permission_callback` mirrors
 * {@see \WP_REST_Global_Styles_Controller::update_item_permissions_check()},
 * which delegates to `check_update_permission()` and requires object-level
 * `edit_post` on the global-styles post id. Custom CSS is passed in
 * `styles.css`; the controller exposes `edit_css` only as an action-link hint
 * (`prepare_links()`), and gates the CSS content itself through
 * `wp_filter_global_styles_post()` (`unfiltered_html`). To stay no weaker than
 * the controller's intent, this ability additionally requires `edit_css` when
 * the input includes custom CSS — a strictly tighter check, never a looser one.
 *
 * @since 0.3.0
 */
final class UpdateGlobalStyles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/update-global-styles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Global Styles', 'abilities-catalog' ),
			'description'         => __( 'Updates the active theme global styles (settings and styles) by the global-styles post id. Changes site-wide appearance. Only the provided fields change.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The global styles post ID for the active theme (from templates/get-global-styles).', 'abilities-catalog' ),
					),
					'settings' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The theme.json-shaped settings overrides to store.', 'abilities-catalog' ),
					),
					'styles'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => __( 'The theme.json-shaped style overrides to store. A "css" key holds custom CSS and requires the edit_css capability.', 'abilities-catalog' ),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __( 'The global styles record title.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The global styles post ID.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the global-styles controller's update gate.
	 *
	 * Requires object-level `edit_post` on the global-styles post id (as
	 * `check_update_permission()` does). When the input carries custom CSS
	 * (`styles.css`), additionally requires `edit_css`, matching the controller's
	 * `action-edit-css` intent and never weaker than it. The REST route re-checks
	 * the capability underneath (defense in depth).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the global styles.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return false;
		}

		return ! $this->hasCustomCss( $input ) || current_user_can( 'edit_css' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated post id, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$request = new WP_REST_Request( 'POST', '/wp/v2/global-styles/' . $id );

		// Object-typed fields pass through to the REST route, which validates and
		// re-encodes them via wp_filter_global_styles_post().
		foreach ( array( 'settings', 'styles' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || ! is_array( $input[ $field ] ) ) {
				continue;
			}

			$request->set_param( $field, $input[ $field ] );
		}

		if ( isset( $input['title'] ) && '' !== $input['title'] ) {
			$request->set_param( 'title', (string) $input['title'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id' => (int) ( $data['id'] ?? $id ),
		);
	}

	/**
	 * Reports whether the input contains custom CSS under `styles.css`.
	 *
	 * @param array<string,mixed> $input The validated input data.
	 * @return bool True if a non-empty `styles.css` string is present.
	 */
	private function hasCustomCss( array $input ): bool {
		return isset( $input['styles'] )
			&& is_array( $input['styles'] )
			&& isset( $input['styles']['css'] )
			&& '' !== $input['styles']['css'];
	}
}
