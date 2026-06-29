<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-content/get-post-revision`.
 *
 * Wraps `GET /wp/v2/posts/<parent>/revisions/<id>` via the Abilities REST
 * Adapter. The input schema is DERIVED from the route — the two path captures
 * `parent` and `id` plus the route's query args, including `context`
 * (`view`/`edit`), where `edit` surfaces the stored `*_raw` fields. The output
 * is OVERRIDDEN to the catalog's flat field set through {@see shapeOutput()}.
 * Permission delegates to the route's own check (no `require_permission` floor
 * is set): the route enforces `edit_post` on the parent post, so `execute()`
 * surfaces its specific errors (`rest_post_invalid_parent` 404,
 * `rest_post_invalid_id` 404, `rest_revision_parent_id_mismatch` 404,
 * `rest_cannot_read` 403) instead of masking them as a permission failure.
 *
 * @since 0.1.0
 */
final class GetPostRevision implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-post-revision';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/posts/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Post Revision', 'abilities-catalog' ),
				'description'     => __( 'Returns a single post revision by parent post ID and revision ID.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'parent', 'title', 'content', 'excerpt', 'date', 'modified' ),
					'properties'           => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => __( 'The revision ID.', 'abilities-catalog' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => __( 'The parent post ID.', 'abilities-catalog' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'The rendered revision title.', 'abilities-catalog' ),
						),
						'title_raw'   => array(
							'type'        => 'string',
							'description' => __( 'The stored (unrendered) revision title. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'content'     => array(
							'type'        => 'string',
							'description' => __( 'The rendered revision content.', 'abilities-catalog' ),
						),
						'content_raw' => array(
							'type'        => 'string',
							'description' => __( 'The stored block markup of the revision content, for diffing or restoring. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'excerpt'     => array(
							'type'        => 'string',
							'description' => __( 'The rendered revision excerpt.', 'abilities-catalog' ),
						),
						'excerpt_raw' => array(
							'type'        => 'string',
							'description' => __( 'The stored (unrendered) revision excerpt. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'date'        => array(
							'type'        => 'string',
							'description' => __( 'The revision date in site time.', 'abilities-catalog' ),
						),
						'modified'    => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
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
	 * Flattens the REST revision body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST revision body. `title`, `content`, and `excerpt` are un-nested from their
	 * `{ rendered: ... }` shape; the `*_raw` keys are added only when REST returns a
	 * `raw` sub-field (edit context). `$input` and `$response` are part of the callback
	 * signature but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST revision body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat revision fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$result = array(
			'id'       => (int) ( $data['id'] ?? 0 ),
			'parent'   => (int) ( $data['parent'] ?? 0 ),
			'title'    => (string) ( $data['title']['rendered'] ?? '' ),
			'content'  => (string) ( $data['content']['rendered'] ?? '' ),
			'excerpt'  => (string) ( $data['excerpt']['rendered'] ?? '' ),
			'date'     => (string) ( $data['date'] ?? '' ),
			'modified' => (string) ( $data['modified'] ?? '' ),
		);

		if ( isset( $data['title']['raw'] ) ) {
			$result['title_raw'] = (string) $data['title']['raw'];
		}
		if ( isset( $data['content']['raw'] ) ) {
			$result['content_raw'] = (string) $data['content']['raw'];
		}
		if ( isset( $data['excerpt']['raw'] ) ) {
			$result['excerpt_raw'] = (string) $data['excerpt']['raw'];
		}

		return $result;
	}
}
