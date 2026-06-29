<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Menus;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `og-menus/delete-navigation`.
 *
 * Wraps `DELETE /wp/v2/navigation/<id>` via the Abilities REST Adapter, deleting a
 * block navigation menu (a `wp_navigation` post). Unlike classic menus,
 * `wp_navigation` supports Trash: by default this trashes the navigation
 * (recoverable); set `force` to true to delete it permanently. A navigation menu
 * can be reused across the site, so deleting it affects every Navigation block that
 * references it.
 *
 * Permission delegates to the wrapped route's own check, which mirrors the posts
 * controller `delete_item_permissions_check`: object-level `delete_post` on the
 * navigation id, which `map_meta_cap` resolves to `edit_theme_options` for
 * `wp_navigation`. No `require_permission` floor is set — the route cap equals the
 * catalog cap, so the route stays the authority and its real `WP_Error` (e.g.
 * `rest_post_invalid_id` 404, `rest_cannot_delete` 403) surfaces through
 * `execute()` unchanged. The input keeps both `id` and the caller's `force` flag;
 * {@see shapeOutput()} flattens the route response to the catalog's field set.
 * Destructive: exposed to the browser only when both the write and destructive
 * adapter settings are on. Capability is the hard guard.
 *
 * @since 0.5.0
 */
final class DeleteNavigation implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-menus/delete-navigation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/navigation/(?P<id>[\d]+)',
				'method'          => 'DELETE',
				'label'           => __( 'Delete Navigation', 'abilities-catalog' ),
				'description'     => __( 'Deletes a block navigation menu (a wp_navigation post) by ID. By default it is moved to Trash and can be restored; set force to true to delete it permanently. A navigation menu may be used by Navigation blocks across the site, so deleting it affects every place it appears. The default (force false) path can be rejected: if Trash is disabled site-wide it fails with rest_trash_not_supported (501), and if the menu is already in Trash it fails with rest_already_trashed (410). In those cases set force to true to delete permanently.', 'abilities-catalog' ),
				'category'        => 'og-core-menus',
				'input_schema'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The navigation menu (wp_navigation post) ID to delete. Discover it with og-menus/list-navigation or og-menus/get-navigation.', 'abilities-catalog' ),
						),
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'If true, delete permanently (bypass Trash). If false (default), move to Trash; this can fail with rest_trash_not_supported (501) when Trash is disabled site-wide, or rest_already_trashed (410) when the menu is already in Trash.', 'abilities-catalog' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'   => array(
					'type'                 => 'object',
					'required'             => array( 'deleted', 'trashed', 'id' ),
					'properties'           => array(
						'deleted' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the navigation was permanently deleted.', 'abilities-catalog' ),
						),
						'trashed' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the navigation was moved to Trash (recoverable).', 'abilities-catalog' ),
						),
						'id'      => array(
							'type'        => 'integer',
							'description' => __( 'The deleted navigation menu ID.', 'abilities-catalog' ),
						),
						'title'   => array(
							'type'        => 'string',
							'description' => __( 'The title of the navigation menu that was deleted or trashed.', 'abilities-catalog' ),
						),
						'status'  => array(
							'type'        => 'string',
							'description' => __( 'The post status after the operation: "trash" when trashed, or the previous status when permanently deleted.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
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
	 * Flattens the REST navigation-delete response to the catalog's field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * route response. With `force=true` the route returns `{deleted:true, previous:{…}}`,
	 * so the snapshot (title, status) is read from `previous`; with `force=false` it
	 * returns the trashed post object (status `trash`), read directly. The `id` echoes
	 * the original ability input. `$response` is part of the callback signature but
	 * unused — the body and input carry everything this shape needs.
	 *
	 * @param mixed               $data     The REST response body (associative array).
	 * @param array<string,mixed> $input    The original ability input (carries `id`).
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The deleted/trashed flags, id, and a snapshot (title, status) of the affected menu.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();
		$id   = (int) ( $input['id'] ?? 0 );

		// With force=true the route returns {deleted:true, previous:{…}}.
		// With force=false it returns the trashed post object (status "trash").
		$deleted = (bool) ( $data['deleted'] ?? false );
		$source  = $deleted && isset( $data['previous'] ) && is_array( $data['previous'] )
			? $data['previous']
			: $data;
		$status  = isset( $source['status'] ) ? (string) $source['status'] : '';
		$trashed = ! $deleted && 'trash' === $status;

		$result = array(
			'deleted' => $deleted,
			'trashed' => $trashed,
			'id'      => $id,
		);

		if ( isset( $source['title'] ) ) {
			$title = $source['title'];
			if ( is_array( $title ) ) {
				$title = $title['rendered'] ?? ( $title['raw'] ?? '' );
			}
			$result['title'] = (string) $title;
		}

		if ( '' !== $status ) {
			$result['status'] = $status;
		}

		return $result;
	}
}
