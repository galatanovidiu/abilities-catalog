<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Search;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `search/search-content`.
 *
 * Wraps `GET /wp/v2/search` via `rest_do_request()` and shapes the result. This
 * is WordPress's unified search across object types: posts and pages, terms, and
 * post formats. Returns a flattened list (id, title, url, type, subtype) so an
 * agent can find a piece of content by keyword and then read or edit it with the
 * matching ability. Use this when you do not know the id of the content you need.
 * Read-only.
 *
 * @since 0.5.0
 */
final class SearchContent implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'search/search-content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Search Content', 'abilities-catalog' ),
			'description'         => __( 'Searches site content by keyword using WordPress\'s unified search and returns matches with their id, title, URL, type, and subtype. Search across posts/pages (type "post"), taxonomy terms (type "term"), or post formats. Use this to find content when you do not already know its id.', 'abilities-catalog' ),
			'category'            => 'search',
			'input_schema'        => array(
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
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items' => array(
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
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: `edit_posts` (catalog capability for content search).
	 *
	 * The core search route is public; this catalog ability gates it on
	 * `edit_posts` so search stays an authenticated authoring tool, consistent
	 * with the other content-reading abilities. The hard server-side guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may search content.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request and shaping the result.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped matches, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', (string) ( $input['search'] ?? '' ) );
		$request->set_param( 'type', isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'post' );
		$request->set_param( 'subtype', isset( $input['subtype'] ) && '' !== $input['subtype'] ? sanitize_key( (string) $input['subtype'] ) : 'any' );
		$request->set_param( 'page', isset( $input['page'] ) ? absint( $input['page'] ) : 1 );
		$request->set_param( 'per_page', isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 10 );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data  = rest_get_server()->response_to_data( $response, false );
		$items = array();

		foreach ( is_array( $data ) ? $data : array() as $row ) {
			$items[] = array(
				'id'      => $row['id'] ?? 0,
				'title'   => (string) ( $row['title'] ?? '' ),
				'url'     => (string) ( $row['url'] ?? '' ),
				'type'    => (string) ( $row['type'] ?? '' ),
				'subtype' => (string) ( $row['subtype'] ?? '' ),
			);
		}

		return array(
			'items' => $items,
		);
	}
}
