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
 * T2 destructive write ability: `og-templates/update-template-part`.
 *
 * Wraps `POST /wp/v2/template-parts/<id>` via `rest_do_request()`. The route is
 * hardcoded to the template-parts collection (post type `wp_template_part`,
 * rest_base `template-parts`); unlike the general `og-templates/update-template`,
 * there is no `post_type` input. The part id has the form `theme//slug` (e.g.
 * `twentytwentyfour//header`); the `//` separator is part of the route path and
 * is built by concatenation, never URL-encoded. The outer ability `/run` call is
 * POST (an update); the internal REST verb is POST (EDITABLE).
 *
 * This is annotated DESTRUCTIVE because it creates or replaces a database
 * override of a site-wide template part. The change is recoverable (the
 * file-based source remains and the override can be deleted) but has a high
 * blast radius: a part such as the header or footer renders on most of the site.
 *
 * The `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()}, which
 * delegates to `permissions_check()` and requires `edit_theme_options`. The
 * capability is not object-level for parts. The REST route re-checks it
 * underneath (defense in depth) and handles content sanitization.
 *
 * `area` is a free string in the REST schema (no enum). Core runs a written
 * value through `_filter_block_template_part_area()`, which keeps an allowed
 * area (defaults: uncategorized, header, footer, navigation-overlay; a theme may
 * register more) and silently falls back to `uncategorized` for anything else.
 * This ability therefore surfaces the RESULTING area from the response, not the
 * requested value, so the caller sees what core actually applied.
 *
 * @since 0.4.0
 */
final class UpdateTemplatePart implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/update-template-part';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Template Part', 'abilities-catalog' ),
			'description'         => __( 'Updates a template part (a reusable block region such as a header or footer) by its "theme//slug" id. Change its content, title, description, or area. Creates or replaces a database override that changes site-wide layout: a part like the header or footer renders on most pages, so the blast radius is high. Recoverable by deleting the override with og-templates/delete-template-part. Only the provided fields change; sending content, title, or description as an empty string clears it (area cannot be cleared this way). Returns the resulting area and edit_link (the Site Editor URL) — surface edit_link so a human can review the result.', 'abilities-catalog' ),
			'category'            => 'og-core-templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'          => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The template part id in "theme//slug" form (e.g. "twentytwentyfour//header"). Discover ids via og-templates/list-template-parts.', 'abilities-catalog' ),
					),
					'content'     => array(
						'type'        => 'string',
						'description' => __( 'The raw template part block markup (HTML allowed; sanitized by WordPress).', 'abilities-catalog' ),
					),
					'title'       => array(
						'type'        => 'string',
						'description' => __( 'The template part title.', 'abilities-catalog' ),
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'The template part description.', 'abilities-catalog' ),
					),
					'area'        => array(
						'type'        => 'string',
						'description' => __( 'Where the part is used: change it to header, footer, uncategorized, or navigation-overlay (a theme may register others). An unsupported value falls back to uncategorized. Check the returned area to see what core applied.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'area' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'string',
						'description' => __( 'The template part id in "theme//slug" form.', 'abilities-catalog' ),
					),
					'area'      => array(
						'type'        => 'string',
						'description' => __( 'The resulting template part area after the update (e.g. "header", "footer", "uncategorized"). This is what core applied, which may differ from a requested unsupported value (it falls back to "uncategorized").', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting template part status.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting template part title.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The Site Editor URL where a human can open and review the template part.', 'abilities-catalog' ),
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
	 * Permission check mirroring the templates controller's update gate.
	 *
	 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * The capability is not object-level for template parts. The REST route
	 * re-checks it underneath (defense in depth), so deferring the missing-id
	 * case to the route lets execute() surface the route's specific 404 rather
	 * than masking it as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update template parts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * The route is hardcoded to `/wp/v2/template-parts/<id>`; the "theme//slug"
	 * id is part of the route path, so the "//" is not URL-encoded.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped template part, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request( 'POST', '/wp/v2/template-parts/' . $id );

		// content/title/description forward on KEY presence: an empty string is a
		// deliberate clear, so it is forwarded unchanged (mirrors update-template).
		foreach ( array( 'content', 'title', 'description' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		// area is forwarded only when present AND non-empty: an empty area is not
		// a valid clear (a part always resolves to an area), so an empty value is
		// dropped rather than sent and silently turned into "uncategorized".
		if ( isset( $input['area'] ) && '' !== $input['area'] ) {
			$request->set_param( 'area', (string) $input['area'] );
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

		// Build the edit link from core's canonical helper so it matches the
		// registered part `_edit_link` and runs through the `get_edit_post_link`
		// filter. The update response carries `wp_id` as an int (the underlying
		// post ID); it is 0 only if the response omitted it.
		$wp_id     = (int) ( $data['wp_id'] ?? 0 );
		$edit_link = 0 === $wp_id
			? ''
			: (string) get_edit_post_link( $wp_id, 'raw' );

		return array(
			'id'        => (string) ( $data['id'] ?? $id ),
			'area'      => (string) ( $data['area'] ?? '' ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'title'     => (string) $title,
			'edit_link' => $edit_link,
		);
	}
}
