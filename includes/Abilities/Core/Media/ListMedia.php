<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Media;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\MediaListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-media/list-media`.
 *
 * Wraps `GET /wp/v2/media` via the Abilities REST Adapter and returns the
 * collection plus its total counts. Each row is projected by {@see MediaListShaper}
 * (in {@see shapeOutput()}) into a flat, closed summary; the heavy raw fields
 * (`media_details`, `meta`, `class_list`, `_links`) are never returned (file bytes
 * live behind `og-media/get-media-file`). The `media_type` and `mime_type` filters
 * accept one or more values, mirroring the core collection params. Permission
 * delegates to the route's own check (no `require_permission` floor): published
 * media is public in `view`, and the route enforces `edit_posts` for edit context
 * and per-row visibility itself.
 *
 * @since 0.1.0
 */
final class ListMedia implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-media/list-media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/media',
				'method'          => 'GET',
				'label'           => __( 'List Media', 'abilities-catalog' ),
				'description'     => __( 'Lists media library items with optional search, media_type, mime_type, parent, author, status, and pagination filters.', 'abilities-catalog' ),
				'category'        => 'og-core-media',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to those matching a search term.', 'abilities-catalog' ),
						),
						'media_type' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'image', 'video', 'text', 'application', 'audio' ),
							),
							'description' => __( 'Filter by media type — the field is named "media_type", not "type". One or more of: image, video, text, application, audio. For a specific format use "mime_type" (e.g. "image/png").', 'abilities-catalog' ),
						),
						'mime_type'  => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Limit results to one or more MIME types (e.g. "image/png").', 'abilities-catalog' ),
						),
						'parent'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Limit results to items attached to the given parent post IDs.', 'abilities-catalog' ),
						),
						'author'     => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Limit results to the given author user IDs.', 'abilities-catalog' ),
						),
						'status'     => array(
							'type'        => 'string',
							'description' => __( 'Limit results to a media status (e.g. "inherit").', 'abilities-catalog' ),
						),
						'page'       => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
						),
						'per_page'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
							'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
						),
						'orderby'    => array(
							'type'        => 'string',
							'enum'        => array( 'author', 'date', 'id', 'include', 'modified', 'parent', 'relevance', 'slug', 'include_slugs', 'title' ),
							'description' => __( 'Field to sort by (e.g. "date", "title").', 'abilities-catalog' ),
						),
						'order'      => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => __( 'Sort direction.', 'abilities-catalog' ),
						),
						'context'    => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => MediaListShaper::mediaItemSchema(),
							'description' => __( 'The list of media items.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of media items matching the query.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages available.', 'abilities-catalog' ),
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
	 * Flattens the collection envelope into the catalog's summary-row shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Each row is flattened by
	 * {@see MediaListShaper::mediaSummary()}; the totals carry through unchanged.
	 * `$input` and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat media summary rows and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = MediaListShaper::mediaSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? count( $rows ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
