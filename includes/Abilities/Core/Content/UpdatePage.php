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
 * Write ability: `og-content/update-page`.
 *
 * Wraps `POST /wp/v2/pages/<id>` via the Abilities REST Adapter. The input schema
 * is the catalog's CURATED page field set (not the route's raw arg list), and an
 * {@see normalizeInput()} callback mirrors the catalog's old pass-through rules:
 * drop an empty `date`/`status`/`author` so they do not reach the route, but keep
 * an explicit `parent`/`featured_media` of `0` (detach) and a negative
 * `menu_order`. The output is OVERRIDDEN to the catalog's six-field set through
 * {@see shapeOutput()}, which flattens `title.rendered`/`link`/`status`/`modified`
 * and derives the wp-admin `edit_link`. Permission delegates to the route's own
 * object-level check (no `require_permission` floor): the route is the authority.
 *
 * @since 0.2.0
 */
final class UpdatePage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/update-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/pages/(?P<id>[\d]+)',
				'method'          => 'POST',
				'label'           => __( 'Update Page', 'abilities-catalog' ),
				'description'     => __( 'Updates an existing page by ID. Only the provided fields change. Set status to "publish" to publish it (requires publish capability).', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'             => array(
							'type'        => 'integer',
							'description' => __( 'The page ID to update.', 'abilities-catalog' ),
						),
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
							'description' => __( 'The page status.', 'abilities-catalog' ),
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
							'minimum'     => 0,
							'description' => __( 'The parent page ID, or 0 to detach the current parent.', 'abilities-catalog' ),
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
							'minimum'     => 0,
							'description' => __( 'Attachment ID for the featured image, or 0 to detach the current one.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'normalizeInput' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status', 'link', 'edit_link' ),
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'description' => __( 'The page ID.', 'abilities-catalog' ),
						),
						'title'     => array(
							'type'        => 'string',
							'description' => __( 'The rendered page title.', 'abilities-catalog' ),
						),
						'link'      => array(
							'type'        => 'string',
							'description' => __( 'The page permalink.', 'abilities-catalog' ),
						),
						'status'    => array(
							'type'        => 'string',
							'description' => __( 'The resulting page status.', 'abilities-catalog' ),
						),
						'modified'  => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
						),
						'edit_link' => array(
							'type'        => 'string',
							'description' => __( 'The wp-admin URL to edit the page. Surface this so a human can review the change.', 'abilities-catalog' ),
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
					'screen'       => 'post.php?post={id}&action=edit',
				),
			)
		);
	}

	/**
	 * Normalizes ability input before dispatch, preserving the catalog's pass-through rules.
	 *
	 * Wired as the adapter's `input_callback`, so it runs once per `execute()`, after the
	 * input validates against the schema and before the route dispatches. It mirrors the
	 * catalog's old `execute()`: string fields forward whenever present (including `''`,
	 * so a caller can blank a title or excerpt); `parent` and `featured_media` forward
	 * whenever present (including `0`, to detach); `menu_order` is cast as a signed integer
	 * so a negative value survives; but an empty `date`, an empty `status`, and an empty or
	 * zero `author` are DROPPED — an empty date is invalid REST input, and an empty
	 * status/author is a no-op the catalog never forwarded. `id` is consumed as the path
	 * capture by the adapter; the rest become the request body.
	 *
	 * @param array<string,mixed> $params The validated ability input.
	 * @return array<string,mixed> The transformed params for the REST request.
	 */
	public function normalizeInput( array $params ): array {
		$out = array();

		if ( array_key_exists( 'id', $params ) ) {
			$out['id'] = absint( $params['id'] );
		}

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Forward whenever present, including ''.
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'template' ) as $field ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}

			$out[ $field ] = (string) $params[ $field ];
		}

		// An empty date string is invalid input: core resets the publish date only
		// on date=null, which a date-time string schema cannot express. Skip ''.
		if ( ! empty( $params['date'] ) ) {
			$out['date'] = (string) $params['date'];
		}

		if ( isset( $params['status'] ) && '' !== $params['status'] ) {
			$out['status'] = sanitize_key( (string) $params['status'] );
		}

		if ( ! empty( $params['author'] ) ) {
			$out['author'] = absint( $params['author'] );
		}

		// Forward whenever present, including 0 so the caller can detach the parent.
		if ( array_key_exists( 'parent', $params ) ) {
			$out['parent'] = absint( $params['parent'] );
		}

		if ( isset( $params['menu_order'] ) ) {
			// Core treats menu_order as a signed integer; preserve negatives.
			$out['menu_order'] = (int) $params['menu_order'];
		}

		// Forward whenever present, including 0 so the caller can detach the
		// current featured image.
		if ( array_key_exists( 'featured_media', $params ) ) {
			$out['featured_media'] = absint( $params['featured_media'] );
		}

		return $out;
	}

	/**
	 * Flattens the REST page body to the catalog's six-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the REST
	 * page body. `title` is un-nested from its `{ rendered: ... }` shape; `link`, `status`,
	 * and `modified` copy across with a type cast; `edit_link` is derived from the page id
	 * via {@see get_edit_post_link()}. `$input` and `$response` are part of the callback
	 * signature but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST page body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat page fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data    = is_array( $data ) ? $data : array();
		$page_id = (int) ( $data['id'] ?? 0 );

		return array(
			'id'        => $page_id,
			'title'     => (string) ( $data['title']['rendered'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'modified'  => (string) ( $data['modified'] ?? '' ),
			'edit_link' => (string) get_edit_post_link( $page_id, 'raw' ),
		);
	}
}
