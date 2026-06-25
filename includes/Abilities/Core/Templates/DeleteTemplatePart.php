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
 * T2 destructive write ability: `og-templates/delete-template-part`.
 *
 * Part-first sibling of `og-templates/delete-template`: the route is hardcoded to
 * `DELETE /wp/v2/template-parts/<id>` (post type `wp_template_part`) — there is no
 * `post_type` input. Dispatched with `force=true` via `rest_do_request()`. The id
 * has the form `theme//slug`; the `//` is part of the route path and is NOT
 * URL-encoded.
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
 * deleting its `wp_template_part` post. The `permission_callback` mirrors
 * {@see \WP_REST_Templates_Controller::delete_item_permissions_check()}
 * (`edit_theme_options`); the REST route re-checks it (defense in depth).
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
		return array(
			'label'               => __( 'Delete Template Part', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a template part (a reusable block region such as a header or footer) by its "theme//slug" id. A customized source-backed part (provided by the theme, a plugin, or the site) is reverted to its original source; a user-created part is removed entirely. Parts that exist only as theme files cannot be deleted. This cannot be undone and changes site-wide layout. Returns the deleted part\'s title, slug, area, and original_source so the caller can confirm which part changed and whether it was reverted or removed.', 'abilities-catalog' ),
			'category'            => 'og-core-templates',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the templates controller's delete gate.
	 *
	 * {@see \WP_REST_Templates_Controller::delete_item_permissions_check()}
	 * delegates to `permissions_check()`, which requires `edit_theme_options`.
	 * Not an object-level capability for template parts. The REST route re-checks
	 * it, so deferring lets execute() surface the route's specific error
	 * (rest_template_not_found 404, rest_invalid_template 400) instead of a generic
	 * permission collapse.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete template parts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Hardcodes the template-parts route. Forces `force=true` so the part record is
	 * permanently deleted (reverting a customized source-backed part to its source,
	 * or removing a user-created part). On success the forced-delete response
	 * carries a `previous` snapshot; its canonical id, title, slug, area, and
	 * original_source are flattened into the output. Any REST error is returned
	 * unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag, canonical id, and
	 *                                       flattened part snapshot, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = (string) ( $input['id'] ?? '' );

		// The "theme//slug" id is part of the route path; do not URL-encode the "//".
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/template-parts/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		// With `force=true` core returns array( 'deleted' => true, 'previous' =>
		// <prepared item> ). The snapshot carries the canonical id (core's route
		// sanitizer repairs a single-slash id to "theme//slug") plus title, slug,
		// area, and original_source — flatten them so the caller can confirm which
		// part changed and whether it was reverted vs removed.
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
