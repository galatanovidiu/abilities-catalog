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
 * Read ability: `og-templates/get-template-part`.
 *
 * Wraps `GET /wp/v2/template-parts/<id>` via the Abilities REST Adapter. The id
 * has the form `theme//slug`; the adapter substitutes it into the route's `id`
 * capture, whose sub-pattern accepts the literal `//`, so the value round-trips
 * raw (not URL-encoded). Part-first read: there is no `post_type` input — the route
 * is hardcoded to template parts and `area` is a first-class field. Permission
 * delegates to the route's own check (no `require_permission` floor): the templates
 * route requires `edit_posts` (or edit access to a REST-enabled post type).
 * Read-only.
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
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/template-parts/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Template Part', 'abilities-catalog' ),
				'description'     => __( 'Returns a single site-editor template part by its "theme//slug" id, including its block markup and area (header, footer, etc.). For full templates use og-templates/get-template.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
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
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
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
	 * Maps the REST template-part body to the catalog's flat output shape.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. The rendered
	 * `title` and the raw (or rendered) `content` are flattened from their nested
	 * REST objects, and `original_source` is surfaced only when present. `$input['id']`
	 * is the fallback id. `$response` is part of the callback signature but unused.
	 *
	 * @param mixed               $data     The REST template-part body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat template-part fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = (string) ( $input['id'] ?? '' );

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
