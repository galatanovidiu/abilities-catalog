<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `menus/update-navigation`.
 *
 * Wraps `POST /wp/v2/navigation/<id>` via `rest_do_request()` to update a
 * block-based navigation menu (`wp_navigation` post). The permission check
 * mirrors the posts controller's `update_item_permissions_check`: an object-level
 * `edit_post` check on the navigation post ID, which `map_meta_cap` resolves to
 * `edit_theme_options` for the `wp_navigation` post type. Write annotations
 * (`readonly:false, destructive:false, idempotent:false`) route the call as POST.
 *
 * @since 0.3.0
 */
final class UpdateNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/update-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Navigation Menu', 'abilities-catalog' ),
			'description'         => __( 'Updates an existing block-based navigation menu by ID. Only the supplied fields change.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The navigation menu ID to update. Discover IDs with `menus/list-navigation`.', 'abilities-catalog' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'The navigation menu title.', 'abilities-catalog' ),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __( 'The serialized block markup for the menu items.', 'abilities-catalog' ),
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
						'description' => __( 'The navigation menu post status.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The navigation menu ID.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting navigation menu title.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting navigation menu post status.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The site-editor URL for editing the navigation menu.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check mirroring the posts controller update path, object-aware.
	 *
	 * Checks `edit_post` on the navigation post ID; `map_meta_cap` resolves it to
	 * `edit_theme_options` for `wp_navigation`. The REST route re-checks underneath.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update the navigation menu.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The menu's id, title, status, edit link, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/navigation/' . $id );

		// Forward whenever the key is present, including '' so the caller can clear
		// the title or empty the menu markup. Core writes empty strings on update.
		foreach ( array( 'title', 'content' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$request->set_param( 'status', sanitize_key( (string) $input['status'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data        = rest_get_server()->response_to_data( $response, false );
		$resolved_id = (int) ( $data['id'] ?? $id );

		$title = '';
		if ( isset( $data['title'] ) ) {
			$title = is_array( $data['title'] )
				? (string) ( $data['title']['rendered'] ?? $data['title']['raw'] ?? '' )
				: (string) $data['title'];
		}

		return array(
			'id'        => $resolved_id,
			'title'     => $title,
			'status'    => (string) ( $data['status'] ?? '' ),
			'edit_link' => $resolved_id > 0 ? (string) get_edit_post_link( $resolved_id, 'raw' ) : '',
		);
	}
}
