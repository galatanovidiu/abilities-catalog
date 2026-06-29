<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Themes;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ThemeListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-themes/list-themes`.
 *
 * Wraps `GET /wp/v2/themes` via the Abilities REST Adapter and returns the
 * installed themes plus their totals. Each row is projected by
 * {@see ThemeListShaper} (in {@see shapeOutput()}) into a flat, closed summary;
 * the raw REST objects (`_links`, nested rendered fields, active-theme-only deep
 * fields) are never returned. A `require_permission` floor keeps the catalog's
 * original cap (`switch_themes` or `edit_theme_options`).
 *
 * @since 0.1.0
 */
final class ListThemes implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-themes/list-themes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/themes',
				'method'             => 'GET',
				'label'              => __( 'List Themes', 'abilities-catalog' ),
				'description'        => __( 'Lists installed themes, optionally filtered by status.', 'abilities-catalog' ),
				'category'           => 'og-core-themes',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'  => array(
							'type'        => 'string',
							'enum'        => array( 'active', 'inactive' ),
							'description' => __( 'Limit results to a theme status: "active" or "inactive".', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'items', 'total', 'total_pages' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => ThemeListShaper::themeItemSchema(),
							'description' => __( 'The list of installed themes.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of themes matching the query.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages available.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'require_permission' => array( $this, 'requirePermission' ),
				'output_callback'    => array( $this, 'shapeOutput' ),
				'meta'               => array(
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
	 * Permission floor: ability to manage themes or theme options.
	 *
	 * Mirrors the catalog's original cap. The route's own check still runs at
	 * dispatch.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		return current_user_can( 'switch_themes' ) || current_user_can( 'edit_theme_options' );
	}

	/**
	 * Flattens the collection envelope into the catalog's summary-row shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Each row is flattened by
	 * {@see ThemeListShaper::themeSummary()}; the totals carry through unchanged.
	 * `$input` and `$response` are part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat theme summary rows and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = ThemeListShaper::themeSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? count( $rows ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
