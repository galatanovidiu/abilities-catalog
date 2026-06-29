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
 * Read ability: `og-templates/list-synced-patterns`.
 *
 * Wraps `GET /wp/v2/blocks` via the Abilities REST Adapter. A user pattern is a
 * reusable block stored as a `wp_block` post. The route lists the whole user
 * pattern library, not just synced ones: a fully synced pattern updates every
 * place it is inserted, while `partial` and `unsynced` patterns do not. The
 * blocks route is a `get_items` collection, so the adapter wraps the result in
 * `{ items, total, total_pages }` (reading `X-WP-Total`/`X-WP-TotalPages` from the
 * headers); {@see shapeOutput()} flattens each row to (id, title, slug, status,
 * modified, sync_status) so an agent can find a pattern's id, tell its sync state
 * apart, and then read it with `og-templates/get-pattern`. This is the editable
 * user pattern library, distinct from `og-templates/list-patterns` (the read-only
 * registered pattern registry). Permission delegates to the route's own check (no
 * `require_permission` floor is set), so visibility follows REST. Read-only.
 *
 * @since 0.5.0
 */
final class ListSyncedPatterns implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/list-synced-patterns';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/blocks',
				'method'          => 'GET',
				'label'           => __( 'List Synced Patterns', 'abilities-catalog' ),
				'description'     => __( 'Lists the user pattern library (reusable blocks, post type "wp_block"): synced, partial, and unsynced patterns. Returns id, title, slug, status, and sync_status (empty for fully synced, otherwise "partial" or "unsynced") so the pattern can then be read with the get-pattern ability. This is the editable user pattern library, not the read-only registered pattern registry.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'context'  => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'The request context. Defaults to "view".', 'abilities-catalog' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'The page of results to return.', 'abilities-catalog' ),
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
							'description' => __( 'The number of synced patterns per page (1-100).', 'abilities-catalog' ),
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
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'id', 'title', 'status' ),
								'properties'           => array(
									'id'          => array(
										'type'        => 'integer',
										'description' => __( 'The user pattern (wp_block) post ID.', 'abilities-catalog' ),
									),
									'title'       => array(
										'type'        => 'string',
										'description' => __( 'The user pattern title.', 'abilities-catalog' ),
									),
									'slug'        => array(
										'type'        => 'string',
										'description' => __( 'The user pattern slug.', 'abilities-catalog' ),
									),
									'status'      => array(
										'type'        => 'string',
										'description' => __( 'The user pattern post status.', 'abilities-catalog' ),
									),
									'modified'    => array(
										'type'        => 'string',
										'description' => __( 'The last-modified date (site time).', 'abilities-catalog' ),
									),
									'sync_status' => array(
										'type'        => 'string',
										'description' => __( 'The pattern sync status: empty for a fully synced pattern, otherwise "partial" or "unsynced".', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of user patterns (synced, partial, and unsynced).', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of user patterns matching the query.', 'abilities-catalog' ),
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
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Flattens the wrapped collection to the catalog's row shape and totals.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. The
	 * blocks route is a `get_items` collection, so the adapter hands this callback
	 * the `{ items, total, total_pages }` envelope: `items` is the raw REST row
	 * list, and the totals come from the `X-WP-Total`/`X-WP-TotalPages` headers.
	 * Each row is flattened to the six-field shape, un-nesting `title` from its
	 * `{ rendered, raw }` form and reading `sync_status` from the row's
	 * `wp_pattern_sync_status` field. `$input` and `$response` are part of the
	 * callback signature but unused here — the envelope carries everything needed.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The shaped collection.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$rows  = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$items = array();

		foreach ( $rows as $row ) {
			$title = $row['title'] ?? '';
			if ( is_array( $title ) ) {
				$title = $title['rendered'] ?? ( $title['raw'] ?? '' );
			}

			$items[] = array(
				'id'          => (int) ( $row['id'] ?? 0 ),
				'title'       => (string) $title,
				'slug'        => (string) ( $row['slug'] ?? '' ),
				'status'      => (string) ( $row['status'] ?? '' ),
				'modified'    => (string) ( $row['modified'] ?? '' ),
				'sync_status' => (string) ( $row['wp_pattern_sync_status'] ?? '' ),
			);
		}

		return array(
			'items'       => $items,
			'total'       => (int) ( $data['total'] ?? 0 ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
