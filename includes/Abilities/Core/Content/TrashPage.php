<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 write ability: `og-content/trash-page`.
 *
 * Wraps `DELETE /wp/v2/pages/<id>` with `force=false` via `rest_do_request()`,
 * moving the page to Trash (recoverable). The `permission_callback` enforces the
 * type-level `delete_pages` capability as a coarse guard; object-level
 * `delete_post` is enforced by the wrapped route. When Trash is disabled on the
 * site (`EMPTY_TRASH_DAYS` is 0) or by the `rest_page_trashable` filter, the REST
 * route returns a 501 `rest_trash_not_supported` error, which is surfaced
 * unchanged; this ability never calls `wp_trash_post()` directly.
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
		return array(
			'label'               => __( 'Trash Page', 'abilities-catalog' ),
			'description'         => __( 'Moves a page to the Trash by ID. The page is recoverable. Fails if Trash is disabled on the site or by a filter. If the page is the site\'s front page or posts page, trashing it resets the homepage / posts-page reading settings, and restoring the page does not restore those settings.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
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
			'output_schema'       => array(
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
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
				'show_in_rest' => true,
				'screen'       => 'edit.php?post_type=page',
			),
		);
	}

	/**
	 * Permission check: type-level `delete_pages` as the coarse guard.
	 *
	 * Object-independent so a missing or non-existent id is not masked as a
	 * permission failure. The object-level `delete_post` check and the specific
	 * `rest_post_invalid_id` (404) / `rest_cannot_delete` (403) errors come from
	 * the wrapped `DELETE /wp/v2/pages/<id>` route in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may trash pages.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'delete_pages' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request.
	 *
	 * Forces `force=false` so the page is trashed (not permanently deleted). A
	 * 501 `rest_trash_not_supported` error from the route (Trash disabled on the
	 * site or by the `rest_page_trashable` filter) is returned to the caller
	 * unchanged.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The page's id, title, and status, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/pages/' . $id );
		$request->set_param( 'force', false );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$page_id = (int) ( $data['id'] ?? $id );

		// No edit_link: a trashed page cannot be edited. wp-admin/post.php wp_die()s
		// with HTTP 409 ("you cannot edit this item because it is in the Trash") for
		// any page whose status is 'trash', so get_edit_post_link() would hand back a
		// URL that dead-ends. The page must be restored before it can be edited.
		return array(
			'id'     => $page_id,
			'title'  => (string) ( $data['title']['rendered'] ?? '' ),
			'status' => (string) ( $data['status'] ?? 'trash' ),
		);
	}
}
