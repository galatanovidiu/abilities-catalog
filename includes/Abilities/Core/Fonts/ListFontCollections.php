<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\FontListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-fonts/list-font-collections`.
 *
 * Wraps `GET /wp/v2/font-collections` via the Abilities REST Adapter. The route is
 * a collection (`get_items`), so the adapter wraps the body in the catalog envelope
 * `{ items, total, total_pages }`. The input schema is OVERRIDDEN to the catalog's
 * closed page/per_page/context schema, and the output is OVERRIDDEN to the catalog's
 * flat field set through {@see shapeRows()}: each row is projected by
 * {@see FontListShaper} into a flat, closed summary; the heavy `font_families`
 * catalog, `categories`, and `_links` are never returned. Permission delegates to the
 * route's own check (`edit_theme_options`, equal to the catalog cap — no floor), so
 * visibility follows REST.
 *
 * @since 0.1.0
 */
final class ListFontCollections implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-fonts/list-font-collections';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/font-collections',
				'method'          => 'GET',
				'label'           => __( 'List Font Collections', 'abilities-catalog' ),
				'description'     => __( 'Lists available font collections (remote installable-font catalogs) with optional pagination.', 'abilities-catalog' ),
				'category'        => 'og-core-fonts',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
							'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
						),
						'context'  => array(
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
							'items'       => FontListShaper::collectionItemSchema(),
							'description' => __( 'The list of font collections.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of registered font collections. May exceed the number of returned items because collections that fail to load are skipped.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages available.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeRows' ),
				'meta'            => array(
					'abilities_catalog' => array(
						'scope' => 'site',
					),
					'show_in_rest'      => true,
				),
			)
		);
	}

	/**
	 * Projects the collection envelope to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for a collection GET.
	 * Each raw collection item is reduced to a flat, closed summary via
	 * {@see FontListShaper::collectionSummary()}; non-array items drop out (the
	 * skipped/empty rows the catalog excluded before), while `total`/`total_pages`
	 * pass through unchanged — `total` is the route's header-based count
	 * (`X-WP-Total`), which preserves collections skipped from the page. `$input` and
	 * `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped collection and totals.
	 */
	public function shapeRows( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = is_array( $data['items'] ?? null ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = FontListShaper::collectionSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? 0 ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
