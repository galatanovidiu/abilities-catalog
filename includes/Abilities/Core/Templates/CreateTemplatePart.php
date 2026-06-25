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
 * T2 non-destructive write ability: `og-templates/create-template-part`.
 *
 * Wraps `POST /wp/v2/template-parts` (post type `wp_template_part`) via
 * `rest_do_request()`. Creates a new template part (a reusable block region such
 * as a header or footer) as a database record, identified afterwards by its
 * `theme//slug` id, and places it in an area. Unlike the general
 * `og-templates/create-template` — which always creates parts in the
 * `uncategorized` area — this ability accepts an `area` so the caller controls
 * placement. It does NOT modify any theme file and does NOT overwrite an
 * existing customization — use `og-templates/update-template-part` to change one.
 *
 * Annotated as a non-destructive write (`destructive:false`): it only adds a new
 * record. The `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::create_item_permissions_check()}, which
 * requires `edit_theme_options`. The REST route re-checks the capability and
 * sanitizes content (defense in depth).
 *
 * Area semantics (verified against `_filter_block_template_part_area()` in
 * `wp-includes/block-template-utils.php`): the REST `area` field is a free
 * string with no enum. Core accepts any area registered via
 * `get_allowed_block_template_part_areas()` (defaults: `uncategorized`,
 * `header`, `footer`, `navigation-overlay`; a theme may register more) and
 * silently falls back to `uncategorized` for an unsupported value. This ability
 * therefore surfaces the RESULTING `area` from the response, not the requested
 * value, so the caller sees what core actually applied.
 *
 * @since 0.6.0
 */
final class CreateTemplatePart implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/create-template-part';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Template Part', 'abilities-catalog' ),
			'description'         => __( 'Creates a new template part (a reusable block region such as a header or footer) and places it in an area. Returns the new "theme//slug" id, status, the resulting area, and edit_link (the Site Editor URL) — surface edit_link so a human can open and finish the part. Unlike create-template, which always creates parts in the uncategorized area, this ability sets the area you choose. Does not change theme files and does not overwrite an existing part — use update-template-part for that. Send the content field as Gutenberg block markup (e.g. <!-- wp:site-title /-->), not bare HTML.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'slug'        => array(
						'type'        => 'string',
						'minLength'   => 1,
						'pattern'     => '[a-zA-Z0-9_\%-]+',
						'description' => __( 'The template part slug (e.g. "header" or "my-footer"). Only the characters [a-zA-Z0-9_%-] are allowed. Combined with the active theme to form the "theme//slug" id.', 'abilities-catalog' ),
					),
					'area'        => array(
						'type'        => 'string',
						'default'     => 'uncategorized',
						'description' => __( 'Where the part is used: header, footer, uncategorized, or navigation-overlay (a theme may register others). An unsupported value falls back to uncategorized.', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The template part title.', 'abilities-catalog' ),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __( 'The template part content as Gutenberg block markup (e.g. <!-- wp:site-title /-->). Bare HTML saves but degrades to a single Classic block; compose blocks for an editable result.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The template part description.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'slug' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'area', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __( 'The new template part id in "theme//slug" form.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting template part status.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting template part title.', 'abilities-catalog' ),
					),
					'area'      => array(
						'type'        => 'string',
						'description' => __( 'The area core actually applied. May differ from the requested area: an unsupported value falls back to "uncategorized".', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The Site Editor URL where a human can open and edit the new template part.', 'abilities-catalog' ),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the templates controller's create gate.
	 *
	 * {@see \WP_REST_Templates_Controller::create_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * Not an object-level capability for template parts. The REST route
	 * re-checks it.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create template parts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * Hardcodes the `/wp/v2/template-parts` route (post type `wp_template_part`);
	 * there is no `post_type` input. Forwards `area` when supplied non-empty so
	 * core places the part in that area, then surfaces the RESULTING area from
	 * the response (core may fall back to `uncategorized`).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped new part, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'POST', '/wp/v2/template-parts' );

		// Pass the slug through unchanged; the REST route owns validation
		// (pattern `[a-zA-Z0-9_%-]+`, minLength 1). Pre-sanitizing would mask
		// core's specific validation error.
		$request->set_param( 'slug', (string) ( $input['slug'] ?? '' ) );

		// Forward the area when supplied non-empty — the gap-closer the general
		// create-template lacks. Core resolves an unsupported area to
		// `uncategorized`; the resulting area is read back from the response.
		if ( isset( $input['area'] ) && '' !== $input['area'] ) {
			$request->set_param( 'area', (string) $input['area'] );
		}

		foreach ( array( 'content', 'title', 'description' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		$id = (string) ( $data['id'] ?? '' );

		// Build the edit link from core's canonical helper so it matches the
		// registered template `_edit_link` and runs through the
		// `get_edit_post_link` filter. The create response carries `wp_id` as
		// an int (the underlying post ID).
		$wp_id     = (int) ( $data['wp_id'] ?? 0 );
		$edit_link = 0 === $wp_id
			? ''
			: (string) get_edit_post_link( $wp_id, 'raw' );

		return array(
			'id'        => $id,
			'status'    => (string) ( $data['status'] ?? '' ),
			'title'     => (string) $title,
			'area'      => (string) ( $data['area'] ?? '' ),
			'edit_link' => $edit_link,
		);
	}
}
