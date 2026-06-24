<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `fonts/delete-font-family`.
 *
 * Wraps `DELETE /wp/v2/font-families/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting an installed `wp_font_family` post and its font-face assets.
 * The `permission_callback` mirrors the font-families controller: the
 * `wp_font_family` post type maps `delete_posts` to `edit_theme_options`, so
 * deleting a family requires `edit_theme_options`. This ability never calls
 * `wp_delete_post()` directly; it surfaces the REST route's `WP_Error` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteFontFamily implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'fonts/delete-font-family';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Font Family', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes an installed font family and its font-face assets by ID. This cannot be undone and may break typography that references it.', 'abilities-catalog' ),
			'category'            => 'fonts',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The font family post ID to permanently delete. Discover the ID with fonts/list-font-families.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the font family was permanently deleted.', 'abilities-catalog' ),
					),
					'id'              => array(
						'type'        => 'integer',
						'description' => __( 'The deleted font family post ID.', 'abilities-catalog' ),
					),
					'name'            => array(
						'type'        => 'string',
						'description' => __( 'The display name of the deleted font family.', 'abilities-catalog' ),
					),
					'slug'            => array(
						'type'        => 'string',
						'description' => __( 'The slug of the deleted font family.', 'abilities-catalog' ),
					),
					'font_face_count' => array(
						'type'        => 'integer',
						'description' => __( 'The number of font-face child posts (and their files) removed with the family.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
				'screen'            => 'font-library.php',
			),
		);
	}

	/**
	 * Permission check mirroring the REST controller's delete check.
	 *
	 * The `wp_font_family` post type maps the `delete_posts` capability to
	 * `edit_theme_options`, so deleting a font family requires that capability.
	 * The REST route re-checks it (defense in depth).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete a font family.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=true` so the font family is permanently deleted. Any REST error
	 * is returned to the caller unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, id, and removed
	 *                                       family details, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = (int) ( $input['id'] ?? 0 );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/font-families/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data     = rest_get_server()->response_to_data( $response, false );
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();
		$settings = is_array( $previous['font_family_settings'] ?? null ) ? $previous['font_family_settings'] : array();
		$faces    = is_array( $previous['font_faces'] ?? null ) ? $previous['font_faces'] : array();

		return array(
			'deleted'         => (bool) ( $data['deleted'] ?? false ),
			'id'              => $id,
			'name'            => (string) ( $settings['name'] ?? '' ),
			'slug'            => (string) ( $settings['slug'] ?? '' ),
			'font_face_count' => count( $faces ),
		);
	}
}
