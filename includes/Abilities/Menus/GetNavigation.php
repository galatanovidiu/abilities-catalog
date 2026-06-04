<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `menus/get-navigation`.
 *
 * Wraps `GET /wp/v2/navigation/<id>` via `rest_do_request()` and shapes the
 * response into a flat field set. The block-based navigation menu stores its
 * items as serialized blocks inside `content`. Read-only.
 *
 * @since 0.1.0
 */
final class GetNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'menus/get-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Navigation Menu', 'abilities-catalog' ),
			'description'         => __( 'Returns a single block-based navigation menu by ID, including its serialized block content.', 'abilities-catalog' ),
			'category'            => 'menus',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The navigation menu ID.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'       => array(
						'type'        => 'integer',
						'description' => __( 'The navigation menu ID.', 'abilities-catalog' ),
					),
					'title'    => array(
						'type'        => 'string',
						'description' => __( 'The navigation menu title.', 'abilities-catalog' ),
					),
					'content'  => array(
						'type'        => 'string',
						'description' => __( 'The serialized block content of the menu.', 'abilities-catalog' ),
					),
					'status'   => array(
						'type'        => 'string',
						'description' => __( 'The navigation menu post status.', 'abilities-catalog' ),
					),
					'date'     => array(
						'type'        => 'string',
						'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
					),
					'modified' => array(
						'type'        => 'string',
						'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
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
	 * Permission check: managing menus requires `edit_theme_options`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the navigation menu.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * The `wp_navigation` route exposes `title` and `content` as objects with
	 * `raw`/`rendered` subfields; this coerces them to a single string.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat navigation fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/navigation/' . $id );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'       => (int) ( $data['id'] ?? $id ),
			'title'    => $this->coerceField( $data['title'] ?? '' ),
			'content'  => $this->coerceField( $data['content'] ?? '' ),
			'status'   => (string) ( $data['status'] ?? '' ),
			'date'     => (string) ( $data['date'] ?? '' ),
			'modified' => (string) ( $data['modified'] ?? '' ),
		);
	}

	/**
	 * Coerces a `wp_navigation` field that may be a string or a
	 * `raw`/`rendered` object into a single string.
	 *
	 * @param mixed $value The raw field value from the REST response.
	 * @return string The string representation of the field.
	 */
	private function coerceField( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['rendered'] ?? $value['raw'] ?? '' );
		}

		return (string) $value;
	}
}
