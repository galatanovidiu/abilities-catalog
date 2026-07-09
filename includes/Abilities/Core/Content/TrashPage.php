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
 * T1 write ability: `og-content/trash-page`.
 *
 * Wraps `DELETE /wp/v2/pages/<id>` via the Abilities REST Adapter, moving the
 * page to Trash (recoverable). The input schema is OVERRIDDEN to take only `id`;
 * {@see injectForce()} pins `force=false` after validation so the route trashes
 * rather than permanently deletes (it is the route's default, injected for an
 * explicit, stable contract). The output is OVERRIDDEN to the catalog's flat
 * `{ id, title, status }` field set through {@see shapeOutput()}. Permission
 * delegates to the route's own check (no `require_permission` floor is set), so
 * object-level `delete_post` on the wrapped route stays the authority. When Trash
 * is disabled on the site (`EMPTY_TRASH_DAYS` is 0) or by the `rest_page_trashable`
 * filter, the REST route returns a 501 `rest_trash_not_supported` error, surfaced
 * unchanged.
 *
 * @since 0.2.0
 */
final class TrashPage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/trash-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/pages/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Trash Page', 'abilities-catalog' ),
				'description'     => __( 'Moves a page to the Trash by ID. The page is recoverable. Fails if Trash is disabled on the site or by a filter. If the page is the site\'s front page or posts page, trashing it resets the homepage / posts-page reading settings, and restoring the page does not restore those settings.', 'abilities-catalog' ),
				'category'        => 'og-core-content',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The page ID to move to Trash. Obtain it from a list/get content ability (e.g. `og-content/list-pages` or `og-content/get-page`).', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'input_callback'  => array( $this, 'injectForce' ),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => __( 'The page ID.', 'abilities-catalog' ),
						),
						'title'  => array(
							'type'        => 'string',
							'description' => __( 'The rendered title of the trashed page, so a human can confirm what was moved to Trash.', 'abilities-catalog' ),
						),
						'status' => array(
							'type'        => 'string',
							'enum'        => array( 'trash' ),
							'description' => __( 'The resulting page status (trash). The page is recoverable from Pages → Trash. No edit_link is returned: a trashed page cannot be opened in the editor (wp-admin returns HTTP 409); it must be restored first.', 'abilities-catalog' ),
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
					'screen'       => 'edit.php?post_type=page',
				),
			)
		);
	}

	/**
	 * Pins `force=false` so the page is trashed, not permanently deleted.
	 *
	 * Wired as the adapter's `input_callback`, it runs after the ability validates
	 * input against the schema, once per `execute()`, just before dispatch. `force`
	 * is the route's own default, but it is injected here for an explicit, stable
	 * contract — the caller never passes it.
	 *
	 * @param array<string,mixed> $params The validated request params.
	 * @return array<string,mixed> The params with `force` pinned to false.
	 */
	public function injectForce( array $params ): array {
		$params['force'] = false;

		return $params;
	}

	/**
	 * Flattens the trashed-page REST body to the catalog's `{ id, title, status }`.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST page body. The trashed item body carries `title.rendered` and
	 * `status='trash'`; `id` is taken from the original `$input` (the path capture).
	 * No edit_link is returned: a trashed page cannot be edited — wp-admin/post.php
	 * `wp_die()`s with HTTP 409 for any page whose status is `trash`. `$response` is
	 * part of the callback signature but unused here.
	 *
	 * @param mixed               $data     The REST page body (associative array).
	 * @param array<string,mixed> $input    The original ability input.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat page fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'     => (int) ( $data['id'] ?? ( $input['id'] ?? 0 ) ),
			'title'  => (string) ( $data['title']['rendered'] ?? '' ),
			'status' => (string) ( $data['status'] ?? 'trash' ),
		);
	}
}
