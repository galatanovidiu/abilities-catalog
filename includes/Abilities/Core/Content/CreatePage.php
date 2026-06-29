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
 * T1 write ability: `og-content/create-page`.
 *
 * Wraps `POST /wp/v2/pages` via the Abilities REST Adapter. The input schema is
 * OVERRIDDEN to the catalog's curated field set (it adds `parent`, `menu_order`,
 * and `template`, and omits the route's category/tag fields). The output is
 * OVERRIDDEN to the catalog's flat 8-field set through {@see shapeOutput()}, which
 * un-nests `title.rendered` and derives `edit_link`. Permission delegates to the
 * route's own check (no `require_permission` floor is set): the route re-checks
 * `edit_pages` / `publish_pages` / `edit_others_pages` and sanitizes content, so
 * a denial surfaces from the route as its real REST error.
 *
 * @since 0.2.0
 */
final class CreatePage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/create-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/pages',
				'method'          => 'POST',
				'label'           => __( 'Create Page', 'abilities-catalog' ),
				'description'     => __( 'Creates a new page. Defaults to a draft; set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'          => array(
							'type'        => 'string',
							'description' => __( 'The page title.', 'abilities-catalog' ),
						),
						'content'        => array(
							'type'        => 'string',
							'description' => __( 'The page content as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->. Bare HTML is accepted but stored as a single classic block. Use og-templates/list-block-types to discover available blocks.', 'abilities-catalog' ),
						),
						'excerpt'        => array(
							'type'        => 'string',
							'description' => __( 'The page excerpt.', 'abilities-catalog' ),
						),
						'status'         => array(
							'type'        => 'string',
							'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
							'default'     => 'draft',
							'description' => __( 'The page status. Defaults to "draft".', 'abilities-catalog' ),
						),
						'author'         => array(
							'type'        => 'integer',
							'description' => __( 'The author user ID. Setting another user requires the edit_others_pages capability.', 'abilities-catalog' ),
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'The page slug.', 'abilities-catalog' ),
						),
						'date'           => array(
							'type'        => 'string',
							'description' => __( 'The publish date in site time (ISO 8601).', 'abilities-catalog' ),
						),
						'parent'         => array(
							'type'        => 'integer',
							'description' => __( 'The parent post/page ID. Core only requires it to resolve to an existing post.', 'abilities-catalog' ),
						),
						'menu_order'     => array(
							'type'        => 'integer',
							'description' => __( 'The page order value.', 'abilities-catalog' ),
						),
						'template'       => array(
							'type'        => 'string',
							'description' => __( 'A page-template slug registered by the active theme (not an arbitrary file name). Unknown values are rejected.', 'abilities-catalog' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => __( 'Attachment ID for the featured image.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status', 'link', 'edit_link' ),
					'properties'           => array(
						'id'             => array(
							'type'        => 'integer',
							'description' => __( 'The new page ID.', 'abilities-catalog' ),
						),
						'title'          => array(
							'type'        => 'string',
							'description' => __( 'The rendered page title.', 'abilities-catalog' ),
						),
						'link'           => array(
							'type'        => 'string',
							'description' => __( 'The page permalink.', 'abilities-catalog' ),
						),
						'status'         => array(
							'type'        => 'string',
							'description' => __( 'The resulting page status.', 'abilities-catalog' ),
						),
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'The resulting page slug. Core may dedupe the requested slug, so this can differ from the input.', 'abilities-catalog' ),
						),
						'date'           => array(
							'type'        => 'string',
							'description' => __( 'The resulting publish date in site time (ISO 8601).', 'abilities-catalog' ),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => __( 'The applied featured image attachment ID. Returns 0 when none was applied, which signals a requested image that core could not attach.', 'abilities-catalog' ),
						),
						'edit_link'      => array(
							'type'        => 'string',
							'description' => __( 'The wp-admin URL to edit the page. Surface this so a human can review the draft.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Flattens the REST page body to the catalog's 8-field create result.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST page body. `title` is un-nested from its `{ rendered: ... }` shape; the
	 * scalar fields copy across with a type cast and a safe default; and `edit_link`
	 * is derived from the new page ID via `get_edit_post_link()`. `$input` and
	 * `$response` are part of the callback signature but unused here — the body carries
	 * everything this shape needs.
	 *
	 * @param mixed               $data     The REST page body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat create-page result.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data    = is_array( $data ) ? $data : array();
		$page_id = (int) ( $data['id'] ?? 0 );

		return array(
			'id'             => $page_id,
			'title'          => (string) ( $data['title']['rendered'] ?? '' ),
			'link'           => (string) ( $data['link'] ?? '' ),
			'status'         => (string) ( $data['status'] ?? '' ),
			'slug'           => (string) ( $data['slug'] ?? '' ),
			'date'           => (string) ( $data['date'] ?? '' ),
			'featured_media' => (int) ( $data['featured_media'] ?? 0 ),
			'edit_link'      => (string) get_edit_post_link( $page_id, 'raw' ),
		);
	}
}
