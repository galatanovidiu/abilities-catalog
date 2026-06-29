<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Plugins;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\PluginListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-plugins/list-plugins`.
 *
 * Wraps `GET /wp/v2/plugins` via the Abilities REST Adapter and returns the
 * installed plugins readable by the current user. Core skips plugins the user
 * cannot read (and, on multisite, hides network-only plugins without
 * `manage_network_plugins`), so the result is not an exhaustive inventory.
 *
 * The plugins `get_items` route is a collection, so the adapter wraps the body in
 * a `{ items, total, total_pages }` envelope; {@see shapeOutput()} maps the items
 * through {@see PluginListShaper} into flat, closed summary rows and DROPS the
 * `total`/`total_pages` keys to preserve the catalog's closed `{ items }` contract
 * (the plugins route exposes no pagination total). The raw REST fields (rendered
 * `description`, `author`, `requires_*`, `_links`) are never returned — full detail
 * lives behind `og-plugins/get-plugin`.
 *
 * The input schema is OVERRIDDEN to the catalog's closed shape (search, the
 * multisite-aware `status` enum, and `context`); the adapter forwards those as
 * query params. Permission delegates to the route's own check (`activate_plugins`,
 * the same capability the catalog enforced), so no `require_permission` floor is set.
 *
 * @since 0.1.0
 */
final class ListPlugins implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-plugins/list-plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/plugins',
				'method'          => 'GET',
				'label'           => __( 'List Plugins', 'abilities-catalog' ),
				'description'     => __( 'Returns the installed plugins readable by the current user, optionally filtered by search term or activation status.', 'abilities-catalog' ),
				'category'        => 'og-core-plugins',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'  => array(
							'type'        => 'string',
							'description' => __( 'Limit results to plugins matching a search term.', 'abilities-catalog' ),
						),
						'status'  => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => is_multisite()
									? array( 'inactive', 'active', 'network-active' )
									: array( 'inactive', 'active' ),
							),
							'description' => __( 'Limit results to one or more activation statuses.', 'abilities-catalog' ),
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
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items' => array(
							'type'        => 'array',
							'description' => __( 'The installed plugins.', 'abilities-catalog' ),
							'items'       => PluginListShaper::pluginItemSchema(),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Projects the collection envelope to the catalog's closed `{ items }` shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for a collection GET.
	 * Each row is flattened by {@see PluginListShaper::pluginSummary()}; `total`/
	 * `total_pages` are dropped, since the plugins route exposes no pagination total and
	 * the catalog's contract is `{ items }` only. `$input` and `$response` are part of
	 * the callback signature but unused here — the envelope carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat plugin summary rows under `items`.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = PluginListShaper::pluginSummary( $item );
		}

		return array(
			'items' => $rows,
		);
	}
}
