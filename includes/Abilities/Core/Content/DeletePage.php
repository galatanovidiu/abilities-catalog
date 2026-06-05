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
					'deleted'                => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the page was permanently deleted.', 'abilities-catalog' ),
					),
					'id'                     => array(
						'type'        => 'integer',
						'description' => __( 'The deleted page ID.', 'abilities-catalog' ),
					),
					'title'                  => array(
						'type'        => 'string',
						'description' => __( 'The title of the deleted page, so a human can confirm what was removed. No edit_link is returned because the page no longer exists.', 'abilities-catalog' ),
					),
					'was_front_page'         => array(
						'type'        => 'boolean',
						'description' => __( 'True if the deleted page was the static front page. Deleting it reset the reading settings (show_on_front back to "posts", page_on_front to 0). Present only on a successful delete.', 'abilities-catalog' ),
					),
					'was_posts_page'         => array(
						'type'        => 'boolean',
						'description' => __( 'True if the deleted page was the posts page. Deleting it reset page_for_posts to 0. Present only on a successful delete.', 'abilities-catalog' ),
					),
					'previous_parent'        => array(
						'type'        => 'integer',
						'description' => __( 'The deleted page\'s parent ID before deletion (0 if it had no parent). Any child pages were reparented to this ID. Present only on a successful delete.', 'abilities-catalog' ),
					),
					'reparented_child_count' => array(
						'type'        => 'integer',
						'description' => __( 'How many child pages were reparented to previous_parent as a side effect of the delete. Present only on a successful delete.', 'abilities-catalog' ),
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
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		// Snapshot the cascade state BEFORE the delete: once the page row is gone,
		// its parent, its child pages, and the reading-settings tie cannot be read.
		// Core's wp_delete_post() (permanent path) reparents same-type children to
		// the page's post_parent, and _reset_front_page_settings_for_post() resets
		// show_on_front/page_on_front/page_for_posts when the page is the front or
		// posts page. These are otherwise-invisible secondary mutations.
		$cascade = $this->snapshotCascade( $id );

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/pages/' . $id );
		$request->set_param( 'force', true );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'deleted'                => (bool) ( $data['deleted'] ?? false ),
			'id'                     => $id,
			'title'                  => (string) ( $data['previous']['title']['rendered'] ?? '' ),
			'was_front_page'         => $cascade['was_front_page'],
			'was_posts_page'         => $cascade['was_posts_page'],
			'previous_parent'        => $cascade['previous_parent'],
			'reparented_child_count' => $cascade['reparented_child_count'],
		);
	}

	/**
	 * Snapshots the secondary mutations a permanent delete of this page triggers.
	 *
	 * Read before dispatch because the page, its parent link, and its children's
	 * parent links are all changed by `wp_delete_post()`. The flags mirror core:
	 * `_reset_front_page_settings_for_post()` (front/posts page) and the child
	 * reparenting query in `wp_delete_post()` (same-type children only).
	 *
	 * The child count uses `get_posts()` with `post_status => 'any'`, which
	 * matches the set core reparents except for trashed children (core's raw
	 * query has no status filter). That edge case — a trashed child of a page
	 * being permanently deleted — is rare and intentionally not counted here, to
	 * keep the wrap on a cacheable core query.
	 *
	 * @param int $id The page ID about to be deleted.
	 * @return array{was_front_page:bool,was_posts_page:bool,previous_parent:int,reparented_child_count:int}
	 */
	private function snapshotCascade( int $id ): array {
		$page = $id > 0 ? get_post( $id ) : null;

		if ( null === $page || 'page' !== $page->post_type ) {
			return array(
				'was_front_page'         => false,
				'was_posts_page'         => false,
				'previous_parent'        => 0,
				'reparented_child_count' => 0,
			);
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- 'suppress_filters' => false keeps the query cacheable, which the sniff documents as safe.
		$children = get_posts(
			array(
				'post_parent'      => $id,
				'post_type'        => 'page',
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return array(
			'was_front_page'         => 'page' === get_option( 'show_on_front' )
				&& (int) get_option( 'page_on_front' ) === $id,
			'was_posts_page'         => (int) get_option( 'page_for_posts' ) === $id,
			'previous_parent'        => (int) $page->post_parent,
			'reparented_child_count' => count( $children ),
		);
	}
}
