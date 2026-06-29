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
 * Read ability: `og-templates/list-template-parts`.
 *
 * Wraps `GET /wp/v2/template-parts` via the Abilities REST Adapter. Returns the
 * active theme's template parts (reusable block regions such as the header and
 * footer), optionally filtered by area. Read-only.
 *
 * The route is hardcoded to `/wp/v2/template-parts`; unlike the general
 * `og-templates/list-templates`, this ability takes no `post_type` and always lists
 * parts, so `area` is a first-class filter. {@see shapeOutput()} projects each row
 * to a flat summary and DROPS the adapter's `total`/`total_pages` to keep the
 * catalog's closed `{ items }` contract (the parts route exposes no pagination
 * total). Permission delegates to the route's own check (no `require_permission`
 * floor): the templates route requires `edit_posts` (or edit access to a
 * REST-enabled post type).
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/template-parts',
				'method'          => 'GET',
				'label'           => __( 'List Template Parts', 'abilities-catalog' ),
				'description'     => __( 'Lists the active theme\'s template parts (reusable block regions like the header and footer), including each part\'s id, slug, area, source, title, and status. Optionally filter by area (e.g. "header"). Use og-templates/get-template-part to read one part\'s block markup. For full block templates (not parts) use og-templates/list-templates.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Projects the collection envelope to the catalog's closed `{ items }` shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Each row is flattened (rendered
	 * `title`, conditional `original_source`); `total`/`total_pages` are dropped to
	 * keep the catalog's `{ items }` contract. `$input` and `$response` are part of
	 * the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat template-part summary rows under `items`.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$rows = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$items = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

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
