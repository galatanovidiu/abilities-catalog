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
 * Read ability: `og-templates/get-pattern`.
 *
 * Wraps `GET /wp/v2/blocks/<id>` via the Abilities REST Adapter. A user pattern
 * is a `wp_block` post (a reusable block / synced pattern). The input schema is
 * OVERRIDDEN with the catalog's tighter one (`id` required, `context` enum
 * `view`/`edit` defaulting to `view`) so the derived schema is replaced. The
 * output is OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}.
 *
 * Permission delegates to the route's own check (no `require_permission` floor):
 * core maps the `wp_block` `read` capability to `edit_posts`, which is the route's
 * coarse read floor — the catalog's old `edit_posts` baseline matched it, so there
 * is nothing to widen. The route's object-level `read_post` denial and its
 * `rest_post_invalid_id` 404 now surface through `execute()`.
 *
 * @since 0.1.0
 */
final class GetPattern implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/get-pattern';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/blocks/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Pattern', 'abilities-catalog' ),
				'description'     => __( 'Returns a single user pattern (reusable block, post type "wp_block") by ID.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'      => array(
							'type'        => 'integer',
							'description' => __( 'The pattern (wp_block) post ID. Discover IDs via og-templates/list-synced-patterns.', 'abilities-catalog' ),
						),
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The pattern post ID.', 'abilities-catalog' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'The pattern title.', 'abilities-catalog' ),
						),
						'content'     => array(
							'type'        => 'string',
							'description' => __( 'The pattern block markup.', 'abilities-catalog' ),
						),
						'status'      => array(
							'type'        => 'string',
							'description' => __( 'The pattern status.', 'abilities-catalog' ),
						),
						'date'        => array(
							'type'        => 'string',
							'description' => __( 'The publish date in site time.', 'abilities-catalog' ),
						),
						'modified'    => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
						),
						'sync_status' => array(
							'type'        => 'string',
							'description' => __( 'The pattern sync status: "partial", "unsynced", or empty for a fully synced pattern.', 'abilities-catalog' ),
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
	 * Flattens the REST block body to the catalog's 7-field pattern set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST block body. `title` and `content` are un-nested from their
	 * `{ raw, rendered }` shape, preferring `raw`; `sync_status` is read from the
	 * controller's `wp_pattern_sync_status` field; the rest copy across with a type
	 * cast and a safe default so an omitted value comes back as `''` rather than a
	 * missing key. `$input` and `$response` are part of the callback signature but
	 * unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST block body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat pattern fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['raw'] ?? ( $title['rendered'] ?? '' );
		}

		$content = $data['content'] ?? '';
		if ( is_array( $content ) ) {
			$content = $content['raw'] ?? ( $content['rendered'] ?? '' );
		}

		return array(
			'id'          => (int) ( $data['id'] ?? 0 ),
			'title'       => (string) $title,
			'content'     => (string) $content,
			'status'      => (string) ( $data['status'] ?? '' ),
			'date'        => (string) ( $data['date'] ?? '' ),
			'modified'    => (string) ( $data['modified'] ?? '' ),
			'sync_status' => (string) ( $data['wp_pattern_sync_status'] ?? '' ),
		);
	}
}
