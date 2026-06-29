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
 * Read ability: `og-fonts/list-font-families`.
 *
 * Wraps `GET /wp/v2/font-families` via the Abilities REST Adapter. The input
 * schema is OVERRIDDEN to the catalog's closed `page`/`per_page`/`context` shape,
 * replacing the wide collection-params schema the route would otherwise derive.
 * The route is a `get_items` collection, so the adapter wraps the body as
 * `{ items, total, total_pages }` (reading the `X-WP-Total`/`X-WP-TotalPages`
 * headers) before the output callback runs. The output is OVERRIDDEN to the
 * catalog's flat field set through {@see shapeRows()}, which projects each raw
 * font-family body via {@see FontListShaper}. Permission delegates to the route's
 * own check (no `require_permission` floor is set): the route already requires
 * `edit_theme_options` to list font families, so conversion does not widen access.
 *
 * @since 0.1.0
 */
final class ListFontFamilies implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-fonts/list-font-families';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/font-families',
				'method'          => 'GET',
				'label'           => __( 'List Font Families', 'abilities-catalog' ),
				'description'     => __( 'Lists installed font families with optional pagination.', 'abilities-catalog' ),
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
							'description' => __( 'Response context passed to the REST query; shapes which fields the response includes.', 'abilities-catalog' ),
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
							'items'       => FontListShaper::fontFamilyItemSchema(),
							'description' => __( 'The list of font families.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of font families matching the query.', 'abilities-catalog' ),
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
	 * Projects each raw font-family body to the catalog's flat summary row.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * collection envelope the adapter built: `$data` is
	 * `{ items: [ ...raw font-family bodies ], total, total_pages }`. Each raw item is
	 * reduced to a flat, closed summary by {@see FontListShaper::fontFamilySummary()}
	 * (descriptive fields flattened out of `font_family_settings`, faces reduced to a
	 * count); the totals copy across unchanged. `$input` and `$response` are part of
	 * the callback signature but unused here — the envelope carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The collection envelope (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped collection envelope.
	 */
	public function shapeRows( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = FontListShaper::fontFamilySummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? 0 ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
