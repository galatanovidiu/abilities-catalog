<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 destructive write ability: `content/delete-cpt-item` (generic, keyed by `post_type`).
 *
 * Restricts `post_type` to **post-like deletable** types via the same allow-test
 * the create/update siblings use (see {@see DeleteCptItem::isPostLikeDeletable()}):
 * the type must be `show_in_rest`, its REST controller must be exactly
 * `WP_REST_Posts_Controller`, and its collection route must expose a `POST` handler.
 * Any other registered type — font, global-styles, template, navigation, attachment
 * — is rejected up-front with a stable `unsupported_post_type` (400) error, so the
 * ability never permanently deletes a navigation menu or attachment (which its own
 * description lists as unsupported) or mangles a string-id template via `absint()`.
 *
 * For a supported type it resolves the REST item route via
 * `rest_get_route_for_post_type_items()` (honoring a custom `rest_namespace`) and
 * wraps `DELETE <route>/<id>` with `force=true` via `rest_do_request()`,
 * permanently deleting the item (bypassing the Trash). The `permission_callback`
 * validates the type, then mirrors the posts controller
 * `delete_item_permissions_check`: object-level `delete_post`. This ability never
 * calls `wp_delete_post()` directly; it surfaces the REST route's `WP_Error`
 * unchanged.
 *
 * Destructive: registered, but exposed to the browser only when both the write
 * and destructive adapter settings are on. Capability remains the hard guard.
 *
 * @since 0.4.0
 */
final class DeleteCptItem implements Ability {

	/**
	 * Post types that use the base posts controller but are not plain deletable posts.
	 *
	 * `wp_navigation` is served by the exact `WP_REST_Posts_Controller` and exposes a
	 * collection `POST` route, so the controller-class and route checks alone would
	 * admit it. A navigation menu is not a generic post, so it is excluded explicitly
	 * to keep this ability to plain post-like content.
	 *
	 * @var string[]
	 */
	private const NON_POST_LIKE_TYPES = array( 'wp_navigation' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/delete-cpt-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Custom Post Type Item', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a post-like item of a registered post type by ID, bypassing the Trash. This cannot be undone. Does not support font, global-styles, template, navigation, or attachment types.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'The post type slug (required).', 'abilities-catalog' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The item ID to permanently delete (required).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_type', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the item was permanently deleted.', 'abilities-catalog' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The deleted item ID.', 'abilities-catalog' ),
					),
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'The title of the deleted item, so a human can confirm what was removed. No edit_link is returned because the item no longer exists.', 'abilities-catalog' ),
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
				'screen'       => 'edit.php?post_type={post_type}',
			),
		);
	}

	/**
	 * Permission check: the type's `delete_posts` capability as the coarse guard.
	 *
	 * For an unknown or non-REST type it returns true so `execute()` can surface
	 * the specific `invalid_post_type` (400) error rather than masking it as a
	 * permission failure. The object-level `delete_post` check and the
	 * `rest_post_invalid_id` (404) / `rest_cannot_delete` (403) errors come from
	 * the wrapped REST route in `execute()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may delete items of this type.
	 */
	public function hasPermission( $input ): bool {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return true;
		}

		return current_user_can( $obj->cap->delete_posts );
	}

	/**
	 * Executes the ability by dispatching the internal REST delete request with
	 * `force=true` (permanent delete, not Trash).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and id, or an error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';
		$id        = absint( $input['id'] );

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'The requested post type does not exist or is not available in REST.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isPostLikeDeletable( $post_type ) ) {
			return new WP_Error(
				'unsupported_post_type',
				__( 'The requested post type is not a post-like deletable type. Font, global-styles, template, navigation, and attachment types use a different delete contract and are not supported by this ability. Use the dedicated fonts/*, templates/*, menus/*, and media/* abilities for those.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$items_route = rest_get_route_for_post_type_items( $post_type );
		if ( '' === $items_route ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'The requested post type does not exist or is not available in REST.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$request = new WP_REST_Request( 'DELETE', $items_route . '/' . $id );
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

	/**
	 * Tests whether a post type is a plain post-like deletable type.
	 *
	 * Uses the same controller-and-route signal as the create/update siblings
	 * ({@see CreateCptItem::isPostLikeCreatable()}): a type passes only when it is
	 * `show_in_rest`, its REST controller is exactly `WP_REST_Posts_Controller`
	 * (not a subclass such as attachments, menu items, blocks, global styles, or
	 * font families), its collection route exposes a `POST` handler (which excludes
	 * `wp_global_styles`), and it is not in {@see self::NON_POST_LIKE_TYPES}
	 * (`wp_navigation`). Such a type also exposes a standard item `DELETE` route, so
	 * this gate keeps the ability to plain post-like content and rejects the
	 * dedicated-ability types (fonts, templates, navigation, attachments) up-front
	 * instead of permanently deleting them — or mangling a string-id template via
	 * `absint()`.
	 *
	 * @param string $post_type The post type slug.
	 * @return bool True when the type is a plain post-like deletable type.
	 */
	private function isPostLikeDeletable( string $post_type ): bool {
		if ( in_array( $post_type, self::NON_POST_LIKE_TYPES, true ) ) {
			return false;
		}

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return false;
		}

		$controller = $obj->get_rest_controller();
		if ( ! $controller || WP_REST_Posts_Controller::class !== get_class( $controller ) ) {
			return false;
		}

		return $this->hasCollectionCreateRoute( $post_type );
	}

	/**
	 * Tests whether the type's REST collection route exposes a POST handler.
	 *
	 * @param string $post_type The post type slug.
	 * @return bool True when a CREATABLE handler is registered on the collection route.
	 */
	private function hasCollectionCreateRoute( string $post_type ): bool {
		$route = rest_get_route_for_post_type_items( $post_type );
		if ( '' === $route ) {
			return false;
		}

		$routes = rest_get_server()->get_routes();
		if ( ! isset( $routes[ $route ] ) ) {
			return false;
		}

		foreach ( $routes[ $route ] as $handler ) {
			$methods = isset( $handler['methods'] ) ? (array) $handler['methods'] : array();
			if ( ! empty( $methods['POST'] ) ) {
				return true;
			}
		}

		return false;
	}
}
