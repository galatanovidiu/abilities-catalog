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
 * Read ability: `og-templates/list-template-parts`.
 *
 * Wraps `GET /wp/v2/template-parts` via `rest_do_request()`. Returns the active
 * theme's template parts (reusable block regions such as the header and footer),
 * optionally filtered by area. Read-only.
 *
 * The route is hardcoded to `/wp/v2/template-parts`; unlike the general
 * `og-templates/list-templates`, this ability takes no `post_type` and always lists
 * parts, so `area` is a first-class filter. Each row's `area` is surfaced from
 * the response (`area` is a plain string in the controller schema; core accepts
 * custom areas and falls back to "uncategorized" for unsupported ones on write).
 *
 * @since 0.3.0
 */
final class ListTemplateParts implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-template-parts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Template Parts', 'abilities-catalog' ),
			'description'         => __( 'Lists the active theme\'s template parts (reusable block regions like the header and footer), including each part\'s id, slug, area, source, title, and status. Optionally filter by area (e.g. "header"). Use og-templates/get-template-part to read one part\'s block markup. For full block templates (not parts) use og-templates/list-templates.', 'abilities-catalog' ),
			'category'            => 'og-core-templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
					'area'    => array(
						'type'        => 'string',
						'description' => __( 'Filter to one area. Common built-ins are "header", "footer", "uncategorized", and "navigation-overlay" (a theme or plugin may register others). Omit to list every part.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
						'type'        => 'array',
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id' ),
							'properties'           => array(
								'id'              => array(
									'type'        => 'string',
									'description' => __( 'The template-part id in "theme//slug" form (e.g. "twentytwentyfive//header"). Pass it to og-templates/get-template-part.', 'abilities-catalog' ),
								),
								'slug'            => array(
									'type'        => 'string',
									'description' => __( 'The template-part slug.', 'abilities-catalog' ),
								),
								'theme'           => array(
									'type'        => 'string',
									'description' => __( 'The theme the part belongs to.', 'abilities-catalog' ),
								),
								'area'            => array(
									'type'        => 'string',
									'description' => __( 'Where the part is used: "header", "footer", "uncategorized", "navigation-overlay", or a theme-registered area.', 'abilities-catalog' ),
								),
								'source'          => array(
									'type'        => 'string',
									'description' => __( 'The source: "theme" (file-based) or "custom" (DB override).', 'abilities-catalog' ),
								),
								'title'           => array(
									'type'        => 'string',
									'description' => __( 'The rendered template-part title.', 'abilities-catalog' ),
								),
								'status'          => array(
									'type'        => 'string',
									'description' => __( 'The template-part status.', 'abilities-catalog' ),
								),
								'original_source' => array(
									'type'        => 'string',
									'description' => __( 'The original provenance: "theme", "plugin", "site", or "user". Distinguishes a user-created part from a customized one.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
						'description' => __( 'The list of template parts as flat summary rows. Use og-templates/get-template-part for a single part with its block markup.', 'abilities-catalog' ),
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
	 * Permission check: `edit_theme_options` (catalog capability for templates).
	 *
	 * The wrapped read route is looser (`edit_posts`), but the sibling template
	 * abilities all gate on `edit_theme_options`; this mirrors them — consistent
	 * and never weaker than the route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read template parts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/template-parts' );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		if ( isset( $input['area'] ) && '' !== $input['area'] ) {
			$request->set_param( 'area', (string) $input['area'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
			$title = $row['title'] ?? '';
			if ( is_array( $title ) ) {
				$title = $title['rendered'] ?? '';
			}

			$item = array(
				'id'     => (string) ( $row['id'] ?? '' ),
				'slug'   => (string) ( $row['slug'] ?? '' ),
				'theme'  => (string) ( $row['theme'] ?? '' ),
				'area'   => (string) ( $row['area'] ?? '' ),
				'source' => (string) ( $row['source'] ?? '' ),
				'title'  => (string) $title,
				'status' => (string) ( $row['status'] ?? '' ),
			);

			// original_source distinguishes a user-created part from a customized one.
			if ( isset( $row['original_source'] ) && '' !== $row['original_source'] ) {
				$item['original_source'] = (string) $row['original_source'];
			}

			$items[] = $item;
		}

		return array(
			'items' => $items,
		);
	}
}
