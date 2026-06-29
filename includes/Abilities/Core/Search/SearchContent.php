<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Search;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-search/search-content`.
 *
 * Wraps `GET /wp/v2/search` via the Abilities REST Adapter and shapes the result
 * in {@see shapeOutput()}. This is WordPress's unified search across object types:
 * posts and pages, terms, and post formats. Returns a flattened list (id, title,
 * url, type, subtype) so an agent can find a piece of content by keyword and then
 * read or edit it with the matching ability. Use this when you do not know the id
 * of the content you need.
 *
 * Core search only surfaces published, public content: published posts of public
 * `show_in_rest` post types (attachments/media excluded) and terms of public
 * `show_in_rest` taxonomies. It does not find drafts, pending, private, or trashed
 * content, nor media. The core search route is public, so a `require_permission`
 * floor keeps the catalog's `edit_posts` cap (search stays an authenticated
 * authoring tool). Read-only.
 *
 * @since 0.5.0
 */
final class SearchContent implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-search/search-content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/search',
				'method'             => 'GET',
				'label'              => __( 'Search Content', 'abilities-catalog' ),
				'description'        => __( 'Searches site content by keyword using WordPress\'s unified search and returns matches with their id, title, URL, type, and subtype. Search across posts/pages (type "post"), taxonomy terms (type "term"), or post formats. Only published, public content is returned: it does not surface drafts, pending, private, or trashed content, nor media. Use this to find content when you do not already know its id.', 'abilities-catalog' ),
				'category'           => 'og-core-search',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'   => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The search keyword(s).', 'abilities-catalog' ),
						),
						'type'     => array(
							'type'        => 'string',
							'enum'        => array( 'post', 'term', 'post-format' ),
							'default'     => 'post',
							'description' => __( 'The object type to search. Defaults to "post" (posts and pages).', 'abilities-catalog' ),
						),
						'subtype'  => array(
							'type'        => 'string',
							'default'     => 'any',
							'description' => __( 'Limit to a single subtype (e.g. a specific post type or taxonomy). Defaults to "any".', 'abilities-catalog' ),
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
							'description' => __( 'The number of results per page (1-100).', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'search' ),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'id', 'title', 'url', 'type' ),
								'properties'           => array(
									'id'      => array(
										'type'        => array( 'integer', 'string' ),
										'description' => __( 'The object ID of the match.', 'abilities-catalog' ),
									),
									'title'   => array(
										'type'        => 'string',
										'description' => __( 'The match title.', 'abilities-catalog' ),
									),
									'url'     => array(
										'type'        => 'string',
										'description' => __( 'The public URL of the match.', 'abilities-catalog' ),
									),
									'type'    => array(
										'type'        => 'string',
										'description' => __( 'The object type (post, term, post-format).', 'abilities-catalog' ),
									),
									'subtype' => array(
										'type'        => 'string',
										'description' => __( 'The object subtype (e.g. post, page, category).', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of search matches.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'The total number of matches across all pages.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'The total number of result pages for the current per_page.', 'abilities-catalog' ),
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
					'keywords'     => array( 'search posts', 'search pages', 'search cpt', 'find content', 'search content' ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission floor: `edit_posts` (catalog capability for content search).
	 *
	 * The core search route is public; this catalog ability gates it on
	 * `edit_posts` so search stays an authenticated authoring tool, consistent with
	 * the other content-reading abilities. The route's own check still runs at
	 * dispatch.
	 *
	 * @param mixed $input The raw ability input. Unused.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Flattens the search collection envelope into the catalog's match-row shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success, over the
	 * `{ items, total, total_pages }` envelope. Core sets the row `type` to the
	 * taxonomy slug for term results (e.g. `category`), not the search type, so the
	 * requested `type` from `$input` is restored to match the declared contract.
	 * Only the post handler sets `subtype`, so that key is kept absent rather than
	 * invented for term/post-format rows. `$response` is part of the callback
	 * signature but unused here.
	 *
	 * @param mixed               $data     The collection envelope (`{ items, total, total_pages }`).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat search matches and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data  = is_array( $data ) ? $data : array();
		$rows  = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
		$type  = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'post';
		$items = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$item = array(
				'id'    => $row['id'] ?? 0,
				'title' => (string) ( $row['title'] ?? '' ),
				'url'   => (string) ( $row['url'] ?? '' ),
				'type'  => $type,
			);

			if ( isset( $row['subtype'] ) ) {
				$item['subtype'] = (string) $row['subtype'];
			}

			$items[] = $item;
		}

		return array(
			'items'       => $items,
			'total'       => (int) ( $data['total'] ?? count( $items ) ),
			'total_pages' => (int) ( $data['total_pages'] ?? 1 ),
		);
	}
}
