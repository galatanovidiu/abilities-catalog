<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-templates/get-template-part`.
 *
 * Wraps `GET /wp/v2/template-parts/<id>` via `rest_do_request()`. The id has the
 * form `theme//slug`; the `//` separator is part of the route path and is built
 * by concatenation so it is not URL-encoded. Part-first read: there is no
 * `post_type` input — the route is hardcoded to template parts and `area` is a
 * first-class field. Read-only.
 *
 * @since 0.1.0
 */
final class GetTemplatePart implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/get-template-part';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Template Part', 'abilities-catalog' ),
			'description'         => __( 'Returns a single site-editor template part by its "theme//slug" id, including its block markup and area (header, footer, etc.). For full templates use og-templates/get-template.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'      => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The template part id in "theme//slug" form (e.g. "twentytwentyfour//header"). Discover ids with og-templates/list-template-parts.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (requires edit access).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id'              => array(
						'type'        => 'string',
						'description' => __( 'The template part id in "theme//slug" form.', 'abilities-catalog' ),
					),
					'slug'            => array(
						'type'        => 'string',
						'description' => __( 'The template part slug.', 'abilities-catalog' ),
					),
					'theme'           => array(
						'type'        => 'string',
						'description' => __( 'The theme the template part belongs to.', 'abilities-catalog' ),
					),
					'area'            => array(
						'type'        => 'string',
						'description' => __( 'Where the part is used: header, footer, uncategorized, or navigation-overlay (a theme may register others).', 'abilities-catalog' ),
					),
					'source'          => array(
						'type'        => 'string',
						'description' => __( 'The source: "theme" (file-based) or "custom" (DB override).', 'abilities-catalog' ),
					),
					'original_source' => array(
						'type'        => 'string',
						'description' => __( 'The original provenance: "theme", "plugin", "site", or "user". Distinguishes a user-created part from a customized theme/plugin/site one.', 'abilities-catalog' ),
					),
					'title'           => array(
						'type'        => 'string',
						'description' => __( 'The rendered template part title.', 'abilities-catalog' ),
					),
					'content'         => array(
						'type'        => 'string',
						'description' => __( 'The raw template part block markup.', 'abilities-catalog' ),
					),
					'description'     => array(
						'type'        => 'string',
						'description' => __( 'The template part description.', 'abilities-catalog' ),
					),
					'status'          => array(
						'type'        => 'string',
						'description' => __( 'The template part status.', 'abilities-catalog' ),
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
	 * Permission check: `edit_theme_options` (catalog capability for templates).
	 *
	 * Mirrors the sibling template CRUD abilities. The read route itself is
	 * looser (`edit_posts`), but the catalog gates all template-part operations
	 * on `edit_theme_options` for consistency, and that is never weaker than the
	 * route's own check.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read site-editor template parts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The shaped template part, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request( 'GET', '/wp/v2/template-parts/' . $id );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['rendered'] ?? '';
		}

		$content = $data['content'] ?? '';
		if ( is_array( $content ) ) {
			$content = $content['raw'] ?? ( $content['rendered'] ?? '' );
		}

		$result = array(
			'id'          => (string) ( $data['id'] ?? $id ),
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'theme'       => (string) ( $data['theme'] ?? '' ),
			// area is always surfaced for parts; core resolves it (falling back
			// to uncategorized for an unsupported value), so report what core applied.
			'area'        => (string) ( $data['area'] ?? '' ),
			'source'      => (string) ( $data['source'] ?? '' ),
			'title'       => (string) $title,
			'content'     => (string) $content,
			'description' => (string) ( $data['description'] ?? '' ),
			'status'      => (string) ( $data['status'] ?? '' ),
		);

		// original_source distinguishes a user-created part from a customized
		// theme/plugin/site one; surface it only when present.
		if ( isset( $data['original_source'] ) && '' !== $data['original_source'] ) {
			$result['original_source'] = (string) $data['original_source'];
		}

		return $result;
	}
}
