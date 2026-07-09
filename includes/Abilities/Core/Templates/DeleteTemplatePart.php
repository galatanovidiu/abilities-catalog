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
 * T2 destructive write ability: `og-templates/delete-template-part`.
 *
 * Wraps `DELETE /wp/v2/template-parts/<id>` (post type `wp_template_part`) via the
 * Abilities REST Adapter. Part-first sibling of `og-templates/delete-template`: the
 * route is hardcoded to template parts — there is no `post_type` input. The id has
 * the form `theme//slug`; the adapter substitutes it into the route's `id` capture,
 * whose sub-pattern accepts the literal `//`, so the value round-trips raw (not
 * URL-encoded). {@see shapeInput()} injects `force=true` (the caller does not pass
 * it), and {@see shapeOutput()} flattens the forced-delete `previous` snapshot into
 * the catalog's narrow output shape.
 *
 * Behaviour depends on the part's source (the REST route enforces this):
 * - A customized SOURCE-BACKED part (a part provided by the theme, a plugin, or
 *   the site, that the user then edited) is reverted to its original source by
 *   deleting the database customization. Its `original_source` resolves to
 *   `theme`, `plugin`, or `site`.
 * - A purely USER-CREATED part is removed entirely (`original_source` `user`).
 * - A part that exists only as a theme file (never customized) cannot be deleted;
 *   the route returns `rest_invalid_template` 400 ("Templates based on theme files
 *   can't be removed.").
 *
 * `force=true` is used because reverting/removing a part means permanently
 * deleting its `wp_template_part` post. Permission delegates to the route's own
 * check (no `require_permission` floor): the templates route's delete gate requires
 * `edit_theme_options`, surfacing its specific error (rest_template_not_found 404,
 * rest_invalid_template 400, rest_cannot_manage_templates 401/403) instead of the
 * Abilities API collapsing it into a generic permission failure.
 *
 * @since 0.6.0
 */
final class DeleteTemplatePart implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-templates/delete-template-part';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/template-parts/(?P<id>([^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)[\/\w%-]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Template Part', 'abilities-catalog' ),
				'description'     => __( 'Permanently deletes a template part (a reusable block region such as a header or footer) by its "theme//slug" id. A customized source-backed part (provided by the theme, a plugin, or the site) is reverted to its original source; a user-created part is removed entirely. Parts that exist only as theme files cannot be deleted. This cannot be undone and changes site-wide layout. Returns the deleted part\'s title, slug, area, and original_source so the caller can confirm which part changed and whether it was reverted or removed.', 'abilities-catalog' ),
				'category'        => 'og-core-templates',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'The template part id in "theme//slug" form (e.g. "twentytwentyfive//header"). Discover ids via og-templates/list-template-parts.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'id' ),
					'properties'           => array(
						'deleted'         => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the template part record was deleted (reverted/removed).', 'abilities-catalog' ),
						),
						'id'              => array(
							'type'        => 'string',
							'description' => __( 'The deleted template part id in canonical "theme//slug" form.', 'abilities-catalog' ),
						),
						'title'           => array(
							'type'        => 'string',
							'description' => __( 'The deleted template part title.', 'abilities-catalog' ),
						),
						'slug'            => array(
							'type'        => 'string',
							'description' => __( 'The deleted template part slug.', 'abilities-catalog' ),
						),
						'area'            => array(
							'type'        => 'string',
							'description' => __( 'The area the deleted part occupied (e.g. "header", "footer", "uncategorized").', 'abilities-catalog' ),
						),
						'original_source' => array(
							'type'        => 'string',
							'description' => __( 'Where the part came from: "theme", "plugin", or "site" for a reverted source-backed part, or "user" for a removed user-created part.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'shapeInput' ),
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'screen'       => 'site-editor.php',
				),
			)
		);
	}

	/**
	 * Forces a permanent delete before dispatch.
	 *
	 * Wired as the adapter's `input_callback`; runs once per `execute()`, at dispatch.
	 * The caller does not pass `force` (it is not in the input schema), so this injects
	 * `force=true` — the part record is permanently deleted, reverting a customized
	 * source-backed part to its source or removing a user-created part. All other
	 * supplied params (the `id` path capture) are forwarded unchanged.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with `force` forced on.
	 */
	public function shapeInput( array $params ): array {
		$params['force'] = true;

		return $params;
	}

	/**
	 * Flattens the forced-delete `previous` snapshot to the catalog's output shape.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success. With
	 * `force=true` core returns `array( 'deleted' => true, 'previous' => <prepared
	 * item> )`. The snapshot carries the canonical id (core's route sanitizer repairs
	 * a single-slash id to "theme//slug") plus title, slug, area, and original_source
	 * — flatten them so the caller can confirm which part changed and whether it was
	 * reverted vs removed. `$input['id']` is the fallback id. `$response` is part of
	 * the callback signature but unused.
	 *
	 * @param mixed               $data     The REST delete body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The deleted flag, canonical id, and flattened part snapshot.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = (string) ( $input['id'] ?? '' );

		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : array();

		$title = $previous['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['raw'] ?? ( $title['rendered'] ?? '' );
		}

		return array(
			'deleted'         => (bool) ( $data['deleted'] ?? false ),
			'id'              => (string) ( $previous['id'] ?? $id ),
			'title'           => (string) $title,
			'slug'            => (string) ( $previous['slug'] ?? '' ),
			'area'            => (string) ( $previous['area'] ?? '' ),
			'original_source' => (string) ( $previous['original_source'] ?? '' ),
		);
	}
}
