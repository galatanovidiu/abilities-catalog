<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-templates/update-template-part`.
 *
 * Wraps `POST /wp/v2/template-parts/<id>` via the Abilities REST Adapter. The route
 * is hardcoded to the template-parts collection (post type `wp_template_part`,
 * rest_base `template-parts`); unlike the general `og-templates/update-template`,
 * there is no `post_type` input. The part id has the form `theme//slug` (e.g.
 * `twentytwentyfour//header`); the adapter substitutes it into the route's `id`
 * capture, whose sub-pattern accepts the literal `//`, so the value round-trips raw
 * (not URL-encoded). The outer ability `/run` call is POST (an update); the internal
 * REST verb is POST (EDITABLE). {@see shapeInput()} forwards only the supplied fields
 * and {@see shapeOutput()} reshapes the body, surfacing the resulting `area` and the
 * Site Editor `edit_link`.
 *
 * This is annotated DESTRUCTIVE because it creates or replaces a database
 * override of a site-wide template part. The change is recoverable (the
 * file-based source remains and the override can be deleted) but has a high
 * blast radius: a part such as the header or footer renders on most of the site.
 *
 * Permission delegates to the route's own check (no `require_permission` floor):
 * {@see \WP_REST_Templates_Controller::update_item_permissions_check()} delegates to
 * `permissions_check()` and requires `edit_theme_options` — the same capability this
 * catalog ability would gate on, so no stricter floor applies. Deferring to the route
 * surfaces its specific `rest_template_not_found` 404 instead of the Abilities API
 * collapsing a missing id into a generic permission failure.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/template-parts/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Template Part', 'abilities-catalog' ),
				'description'     => __( 'Updates a template part (a reusable block region such as a header or footer) by its "theme//slug" id. Change its content, title, description, or area. Creates or replaces a database override that changes site-wide layout: a part like the header or footer renders on most pages, so the blast radius is high. Recoverable by deleting the override with og-templates/delete-template-part. Only the provided fields change; sending content, title, or description as an empty string clears it (area cannot be cleared this way). Returns the resulting area and edit_link (the Site Editor URL) — surface edit_link so a human can review the result.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'site-editor.php',
				),
			)
		);
	}

	/**
	 * Forwards only the supplied update fields to the template-parts route.
	 *
	 * Wired as the adapter's `input_callback`; runs once at dispatch, as the current
	 * user. `content`, `title`, and `description` forward on KEY presence — an empty
	 * string is a deliberate clear, so it is forwarded unchanged. `area` forwards only
	 * when present AND non-empty: an empty area is not a valid clear (a part always
	 * resolves to an area), so an empty value is dropped rather than sent and silently
	 * turned into "uncategorized". `id` stays in the params so the adapter can
	 * substitute it into the route's `id` capture.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The params to dispatch.
	 */
	public function shapeInput( array $params ): array {
		$shaped = array( 'id' => (string) ( $params['id'] ?? '' ) );

		// content/title/description forward on KEY presence: an empty string is a
		// deliberate clear, so it is forwarded unchanged.
		foreach ( array( 'content', 'title', 'description' ) as $field ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}

			$shaped[ $field ] = (string) $params[ $field ];
		}

		// area is forwarded only when present AND non-empty: an empty area is not
		// a valid clear (a part always resolves to an area), so an empty value is
		// dropped rather than sent and silently turned into "uncategorized".
		if ( isset( $params['area'] ) && '' !== $params['area'] ) {
			$shaped['area'] = (string) $params['area'];
		}

		return $shaped;
	}

	/**
	 * Reshapes the updated REST template-part body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. The
	 * rendered `title` is flattened from its nested REST object, and `edit_link` is
	 * built from core's canonical {@see get_edit_post_link()} (so it matches the
	 * registered part `_edit_link` and runs through the `get_edit_post_link` filter).
	 * The update response carries `wp_id` as an int (the underlying post ID); it is 0
	 * only if the response omitted it. `$input['id']` is the fallback id. `$response`
	 * is part of the callback signature but unused.
	 *
	 * @param mixed               $data     The REST template-part body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat template-part fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = (string) ( $input['id'] ?? '' );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

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
