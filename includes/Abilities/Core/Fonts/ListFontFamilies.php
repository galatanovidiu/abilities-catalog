<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Fonts;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\FontListShaper;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-fonts/list-font-families`.
 *
 * Wraps `GET /wp/v2/font-families` via `rest_do_request()` and returns the
 * collection plus its total counts. Each row is projected by {@see FontListShaper}
 * into a flat, closed summary: the descriptive fields are flattened out of
 * `font_family_settings` and the faces are reduced to a count (the full settings
 * and face IDs live behind `og-fonts/get-font-family`). Read-only; requires
 * `edit_theme_options`.
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
		return array(
			'label'               => __( 'List Font Families', 'abilities-catalog' ),
			'description'         => __( 'Lists installed font families with optional pagination.', 'abilities-catalog' ),
			'category'            => 'og-core-fonts',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: requires the theme-options capability (catalog).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read font families.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and totals, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/font-families' );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		if ( isset( $input['page'] ) ) {
			$request->set_param( 'page', absint( $input['page'] ) );
		}
		if ( isset( $input['per_page'] ) ) {
			$request->set_param( 'per_page', absint( $input['per_page'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$items   = rest_get_server()->response_to_data( $response, false );
		$headers = $response->get_headers();

		$rows = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = FontListShaper::fontFamilySummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}
}
