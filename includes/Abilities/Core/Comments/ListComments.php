<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\CommentListShaper;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-comments/list-comments`.
 *
 * Wraps `GET /wp/v2/comments` via the Abilities REST Adapter and returns the
 * collection plus its total counts. Read-only; REST enforces per-row visibility
 * underneath. Defaults `context` to `view` so any caller with the baseline
 * capability can list comments. Pass `context=edit` to include `author_email`;
 * core rejects the whole request with 403 unless the user has `moderate_comments`.
 *
 * The route alone would let an anonymous visitor list approved comments, which is
 * wider than the catalog's baseline. A `require_permission` floor of `edit_posts`
 * keeps the original cap; {@see shapeOutput()} flattens each row through
 * {@see CommentListShaper} and preserves the `{ items, total, total_pages }`
 * envelope.
 *
 * @since 0.1.0
 */
final class ListComments implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/list-comments';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/comments',
				'method'             => 'GET',
				'label'              => __( 'List Comments', 'abilities-catalog' ),
				'description'        => __( 'Lists comments with optional post, status, type, author, search, and pagination filters.', 'abilities-catalog' ),
				'category'           => 'og-core-comments',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'post'         => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Limit results to comments on the given post IDs.', 'abilities-catalog' ),
						),
						'status'       => array(
							'type'        => 'string',
							'description' => __( 'Limit results to a comment status (e.g. "approve", "hold", "spam", "trash").', 'abilities-catalog' ),
						),
						'type'         => array(
							'type'        => 'string',
							'description' => __( 'Limit results to a comment type (e.g. "comment").', 'abilities-catalog' ),
						),
						'author'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Limit results to comments by the given author user IDs.', 'abilities-catalog' ),
						),
						'author_email' => array(
							'type'        => 'string',
							'description' => __( 'Limit results to comments by a given author email address.', 'abilities-catalog' ),
						),
						'search'       => array(
							'type'        => 'string',
							'description' => __( 'Limit results to those matching a search term.', 'abilities-catalog' ),
						),
						'parent'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
							'description' => __( 'Limit results to comments with the given parent comment IDs.', 'abilities-catalog' ),
						),
						'page'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
						),
						'per_page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 10,
							'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
						),
						'orderby'      => array(
							'type'        => 'string',
							'enum'        => array( 'date', 'date_gmt', 'id', 'include', 'post', 'parent', 'type' ),
							'description' => __( 'Field to sort by (e.g. "date", "id").', 'abilities-catalog' ),
						),
						'order'        => array(
							'type'        => 'string',
							'enum'        => array( 'asc', 'desc' ),
							'description' => __( 'Sort direction.', 'abilities-catalog' ),
						),
						'context'      => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (includes author email; requires "moderate_comments").', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => CommentListShaper::commentItemSchema(),
							'description' => __( 'The list of comments as flat summary rows. Use og-comments/get-comment for a single comment.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of comments matching the query.', 'abilities-catalog' ),
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
	 * Permission floor: baseline `edit_posts` to list comments.
	 *
	 * Encodes the catalog baseline capability for `og-comments/list-comments`,
	 * keeping the original cap that the bare route (public for approved comments)
	 * would otherwise widen. Moderation contexts (non-default status, edit context)
	 * need `moderate_comments`, which the route enforces at dispatch; `edit_posts`
	 * is the minimum required to run the query and is not weaker than that baseline.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Flattens the collection envelope into the catalog's summary-row shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * `{ items, total, total_pages }` envelope the adapter builds for a collection
	 * GET. Each row is flattened by {@see CommentListShaper::commentSummary()}; the
	 * `total`/`total_pages` totals carry through unchanged. `$response` is part of
	 * the callback signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat comment summary rows and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$rows[] = CommentListShaper::commentSummary( $item );
		}

		return array(
			'items'       => $rows,
			'total'       => (int) ( $data['total'] ?? count( $rows ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 0 ),
		);
	}
}
