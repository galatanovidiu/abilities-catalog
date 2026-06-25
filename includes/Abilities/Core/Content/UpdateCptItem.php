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
 * T2 write ability: `og-content/update-cpt-item` (generic, keyed by `post_type`).
 *
 * Restricts `post_type` to **post-like updatable** types via a controller-aware
 * allow-test (see {@see UpdateCptItem::isPostLikeCreatable()}): the type must be
 * `show_in_rest`, its REST controller must be exactly `WP_REST_Posts_Controller`
 * (not a subclass), and its collection route must expose a `POST` handler. Any
 * other registered type is rejected up-front with a stable `unsupported_post_type`
 * (400) error, before route resolution — instead of failing late on a no-route
 * (`wp_global_styles`), upload-contract (`attachment`), or different-update-contract
 * (`wp_navigation`) path. This is because `show_in_rest` does not guarantee a
 * post-like update route: subclasses such as global-styles, attachment, menu
 * items, blocks, and font families share the route shape but not the update
 * contract, and templates use a different identifier scheme.
 *
 * Resolves the type's REST item route via `rest_get_route_for_post_type_items()`
 * (honoring a custom `rest_namespace`) and wraps `POST <route>/<id>` via
 * `rest_do_request()` to update an item of any registered `show_in_rest` post
 * type. Mirrors the update fan-out of `og-content/update-post`, but resolves the
 * publish and author capabilities per-type from `get_post_type_object()`. Only
 * the provided fields change. The `permission_callback` uses the type-level
 * `edit_posts` capability as a coarse guard; the object-level `edit_post` check
 * on the target is enforced by the wrapped REST route. It additionally requires
 * `publish_posts` when the requested status would publish, and
 * `edit_others_posts` when reassigning the author.
 * Write annotations (`readonly:false, destructive:false, idempotent:false`)
 * route the call as POST. The REST route re-checks every capability underneath
 * (defense in depth) and handles content sanitization.
 *
 * @since 0.4.0
 */
final class UpdateCptItem implements Ability {

	/**
	 * Post statuses that require the `publish_posts` capability.
	 *
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Post types that use the base posts controller but are not plain updatable posts.
	 *
	 * `wp_navigation` is served by the exact `WP_REST_Posts_Controller` and exposes a
	 * collection `POST` route, so the controller-class and route checks alone would
	 * admit it. A navigation menu is not a generic title/content/excerpt/status post,
	 * so it is excluded explicitly to keep this ability to plain post-like content.
	 *
	 * @var string[]
	 */
	private const NON_POST_LIKE_TYPES = array( 'wp_navigation' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/update-cpt-item';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Custom Post Type Item', 'abilities-catalog' ),
			'description'         => __( 'Updates a post-like item (with title/content/excerpt/status fields) of a registered post type by ID. Only the supplied fields change. Does not support font, global-styles, template, navigation, or attachment types. Set status to "private", "publish", or "future" to publish it (requires publish capability).', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'The post type slug (required). Must be a post-like REST type (title/content/excerpt/status fields); font, global-styles, template, navigation, and attachment types are not supported.', 'abilities-catalog' ),
					),
					'id'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The item ID to update (required).', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The item title.', 'abilities-catalog' ),
					),
					'content'   => array(
						'type'        => 'string',
						'description' => __( 'The item content as Gutenberg block markup, e.g. <!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->. Bare HTML is accepted but stored as a single classic block. Use og-templates/list-block-types to discover available blocks.', 'abilities-catalog' ),
					),
					'excerpt'   => array(
						'type'        => 'string',
						'description' => __( 'The item excerpt.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
						'description' => __( 'The item status.', 'abilities-catalog' ),
					),
					'slug'      => array(
						'type'        => 'string',
						'description' => __( 'The item slug.', 'abilities-catalog' ),
					),
					'date'      => array(
						'type'        => 'string',
						'description' => __( 'The publish date in site time (ISO 8601).', 'abilities-catalog' ),
					),
					'author'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The author user ID. Setting another user requires the edit_others_posts capability.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_type', 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'edit_link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The item ID.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The rendered item title.', 'abilities-catalog' ),
					),
					'link'      => array(
						'type'        => 'string',
						'description' => __( 'The item permalink.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting item status.', 'abilities-catalog' ),
					),
					'modified'  => array(
						'type'        => 'string',
						'description' => __( 'The last-modified date in site time.', 'abilities-catalog' ),
					),
					'type'      => array(
						'type'        => 'string',
						'description' => __( 'The post type slug.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The wp-admin URL to edit the item. Surface this so a human can review the change.', 'abilities-catalog' ),
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
				'screen'       => 'post.php?post={id}&action=edit',
			),
		);
	}

	/**
	 * Permission check encoding the per-type capabilities for updating an item.
	 *
	 * Uses the type's `edit_posts` capability as the coarse guard. For an unknown
	 * or non-REST type it returns true so `execute()` can surface the specific
	 * `invalid_post_type` (400) error rather than masking it as a permission
	 * failure. The object-level `edit_post` check and the
	 * `rest_post_invalid_id` (404) / `rest_cannot_edit` (403) errors come from the
	 * wrapped REST route in `execute()`. Additionally requires `publish_posts` when
	 * the requested status would publish, and `edit_others_posts` when reassigning
	 * the item to another user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may update items of this type.
	 */
	public function hasPermission( $input ): bool {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return true;
		}

		if ( ! current_user_can( $obj->cap->edit_posts ) ) {
			return false;
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$status = sanitize_key( (string) $input['status'] );
			if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( $obj->cap->publish_posts ) ) {
				return false;
			}
		}

		if ( ! empty( $input['author'] ) ) {
			$author = absint( $input['author'] );
			if ( $author !== get_current_user_id() && ! current_user_can( $obj->cap->edit_others_posts ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability by dispatching the internal REST update request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The item's id, link, status, modified, type, or an error.
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

		if ( ! $this->isPostLikeCreatable( $post_type ) ) {
			return new WP_Error(
				'unsupported_post_type',
				__( 'The requested post type is not a post-like updatable type. Font, global-styles, template, navigation, and attachment types use a different update contract and are not supported by this ability. Use the dedicated fonts/* and templates/* abilities for those.', 'abilities-catalog' ),
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

		$request = new WP_REST_Request( 'POST', $items_route . '/' . $id );

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.). Control fields are sanitized here.
		foreach ( array( 'title', 'content', 'excerpt', 'slug', 'date' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		if ( isset( $input['status'] ) && '' !== $input['status'] ) {
			$request->set_param( 'status', sanitize_key( (string) $input['status'] ) );
		}

		if ( ! empty( $input['author'] ) ) {
			$request->set_param( 'author', absint( $input['author'] ) );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data    = rest_get_server()->response_to_data( $response, false );
		$item_id = (int) ( $data['id'] ?? $id );

		return array(
			'id'        => $item_id,
			'title'     => (string) ( $data['title']['rendered'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'status'    => (string) ( $data['status'] ?? '' ),
			'modified'  => (string) ( $data['modified'] ?? '' ),
			'type'      => (string) ( $data['type'] ?? $post_type ),
			'edit_link' => (string) get_edit_post_link( $item_id, 'raw' ),
		);
	}

	/**
	 * Tests whether a post type accepts a post-like collection update.
	 *
	 * A type passes only when all of the following hold:
	 *
	 * 1. It is registered and `show_in_rest`.
	 * 2. Its REST controller is exactly `WP_REST_Posts_Controller` — not a subclass.
	 *    Core subclasses (`WP_REST_Attachments_Controller`,
	 *    `WP_REST_Menu_Items_Controller`, `WP_REST_Blocks_Controller`,
	 *    `WP_REST_Global_Styles_Controller`, `WP_REST_Font_Families_Controller`,
	 *    `WP_REST_Font_Faces_Controller`) share the route shape but use a different
	 *    update contract, so an `instanceof` check would wrongly admit them.
	 *    `WP_REST_Templates_Controller` does not extend the posts controller at all.
	 * 3. Its registered collection route actually exposes a `POST` (CREATABLE)
	 *    handler. `wp_global_styles` uses the posts controller but registers no
	 *    collection-create route, so this rejects it even though it would otherwise
	 *    pass the class check on older cores.
	 * 4. It is not in {@see UpdateCptItem::NON_POST_LIKE_TYPES} (e.g. `wp_navigation`,
	 *    which uses the base controller but is not a plain updatable post).
	 *
	 * @param string $post_type The post type slug.
	 * @return bool True when the type accepts a post-like collection update.
	 */
	private function isPostLikeCreatable( string $post_type ): bool {
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
