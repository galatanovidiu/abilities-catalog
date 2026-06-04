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
 * T2 destructive write ability: `content/delete-page`.
 *
 * Wraps `DELETE /wp/v2/pages/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the page (bypassing the Trash). The `permission_callback`
 * mirrors the pages controller `delete_item_permissions_check`: object-level
 * `delete_post` (mapped to page caps via `map_meta_cap`). This ability never calls
 * `wp_delete_post()` directly; it surfaces the REST route's `WP_Error` unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeletePage implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/delete-page';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Page', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a page by ID, bypassing the Trash. This cannot be undone. Side effects: deleting a page set as the front page or posts page resets the site reading settings (show_on_front, page_on_front, page_for_posts); deleting a page with child pages reparents those children to the deleted page\'s parent.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'description' => __( 'The page ID to permanently delete.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the page was permanently deleted.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted page ID.', 'abilities-catalog' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'The title of the deleted page, so a human can confirm what was removed. No edit_link is returned because the page no longer exists.', 'abilities-catalog' ),
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
				'screen'       => 'edit.php',
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
	 * @return bool True if the current user may delete pages.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'delete_pages' );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete, not Trash).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/pages/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted' => (bool) ( $data['deleted'] ?? false ),
			'id'      => $id,
			'title'   => (string) ( $data['previous']['title']['rendered'] ?? '' ),
		);
	}
}
