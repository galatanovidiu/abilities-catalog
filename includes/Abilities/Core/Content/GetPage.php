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
 * Read ability: `og-content/get-page`.
 *
 * Wraps `GET /wp/v2/pages/<id>` via the Abilities REST Adapter. The input schema
 * is kept CURATED — `id` plus the `context` (`view`/`edit`) and `password` query
 * args the catalog chooses to expose — rather than the route's full derived set.
 * The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}, including `parent` and `menu_order` and the context-only
 * `*_raw` fields. Permission delegates to the route's own check (no
 * `require_permission` floor), so visibility follows REST: anonymous reads of a
 * published public page stay allowed, and `execute()` surfaces the route's real
 * error (`rest_post_invalid_id` 404, `rest_forbidden` 403) instead of masking it.
 *
 * @since 0.1.0
 */
final class GetPage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/pages/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Page', 'abilities-catalog' ),
				'description'     => __( 'Returns a single page by ID, including its rendered title, content, and excerpt.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array(
							'type'        => 'integer',
							'description' => __( 'The page ID.', 'abilities-catalog' ),
						),
						'context'  => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
						),
						'password' => array(
							'type'        => 'string',
							'description' => __( 'Password for a password-protected page.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'title', 'status', 'link' ),
					'properties'           => array(
						'id'                 => array(
							'type'        => 'integer',
							'description' => __( 'The page ID.', 'abilities-catalog' ),
						),
						'title'              => array(
							'type'        => 'string',
							'description' => __( 'The rendered page title.', 'abilities-catalog' ),
						),
						'title_raw'          => array(
							'type'        => 'string',
							'description' => __( 'The stored (unrendered) page title. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'content'            => array(
							'type'        => 'string',
							'description' => __( 'The rendered page content.', 'abilities-catalog' ),
						),
						'content_raw'        => array(
							'type'        => 'string',
							'description' => __( 'The stored block markup of the page content, for diffing or restoring. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'excerpt'            => array(
							'type'        => 'string',
							'description' => __( 'The rendered page excerpt.', 'abilities-catalog' ),
						),
						'excerpt_raw'        => array(
							'type'        => 'string',
							'description' => __( 'The stored (unrendered) page excerpt. Present only when context is "edit".', 'abilities-catalog' ),
						),
						'slug'               => array(
							'type'        => 'string',
							'description' => __( 'The page slug.', 'abilities-catalog' ),
						),
						'status'             => array(
							'type'        => 'string',
							'description' => __( 'The page status.', 'abilities-catalog' ),
						),
						'author'             => array(
							'type'        => 'integer',
							'description' => __( 'The author user ID.', 'abilities-catalog' ),
						),
						'link'               => array(
							'type'        => 'string',
							'description' => __( 'The page URL (may be a non-public draft/preview URL for non-published pages).', 'abilities-catalog' ),
						),
						'password_protected' => array(
							'type'        => 'boolean',
							'description' => __( 'True when the page is password-protected. The rendered content/excerpt are empty unless the correct password is supplied.', 'abilities-catalog' ),
						),
						'date'               => array(
							'type'        => 'string',
							'description' => __( 'The publish date in site time. Empty when no date is set (e.g. some drafts).', 'abilities-catalog' ),
						),
						'modified'           => array(
							'type'        => 'string',
							'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
						),
						'parent'             => array(
							'type'        => 'integer',
							'description' => __( 'The parent page ID.', 'abilities-catalog' ),
						),
						'menu_order'         => array(
							'type'        => 'integer',
							'description' => __( 'The page order value.', 'abilities-catalog' ),
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
	 * Flattens the REST page body to the catalog's flat field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST page body. The rendered fields are un-nested from their `{ rendered: ... }`
	 * shape; the other fields copy across with a type cast and a safe default. The
	 * context-only `*_raw` keys are added through {@see withRawFields()} only when core
	 * supplied them (i.e. in `edit` context), so the output contract stays honest per
	 * context rather than inventing empty raw fields. `$input` and `$response` are part
	 * of the callback signature but unused here — the body carries everything this
	 * shape needs.
	 *
	 * @param mixed               $data     The REST page body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat page fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		$result = array(
			'id'                 => (int) ( $data['id'] ?? 0 ),
			'title'              => (string) ( $data['title']['rendered'] ?? '' ),
			'content'            => (string) ( $data['content']['rendered'] ?? '' ),
			'excerpt'            => (string) ( $data['excerpt']['rendered'] ?? '' ),
			'slug'               => (string) ( $data['slug'] ?? '' ),
			'status'             => (string) ( $data['status'] ?? '' ),
			'author'             => (int) ( $data['author'] ?? 0 ),
			'link'               => (string) ( $data['link'] ?? '' ),
			'password_protected' => (bool) ( $data['content']['protected'] ?? $data['excerpt']['protected'] ?? false ),
			'date'               => (string) ( $data['date'] ?? '' ),
			'modified'           => (string) ( $data['modified'] ?? '' ),
			'parent'             => (int) ( $data['parent'] ?? 0 ),
			'menu_order'         => (int) ( $data['menu_order'] ?? 0 ),
		);

		return $this->withRawFields( $result, $data );
	}

	/**
	 * Adds the stored (raw) block-markup fields when core supplied them.
	 *
	 * Core only includes `title.raw`/`content.raw`/`excerpt.raw` in `edit` context.
	 * In `view` context those keys are absent, so the `*_raw` fields are omitted
	 * rather than invented — keeping the output contract honest per context.
	 *
	 * @param array<string,mixed> $result The flat result being built.
	 * @param array<string,mixed> $data   The REST response data.
	 * @return array<string,mixed> The result with raw fields added when available.
	 */
	private function withRawFields( array $result, array $data ): array {
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
