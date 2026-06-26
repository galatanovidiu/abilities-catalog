<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\ContentListShaper;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-content/list-cpt-items`.
 *
 * Generic collection reader keyed by `post_type`. Restricts `post_type` to
 * **post-like listable** types via a controller-aware allow-test (see
 * {@see ListCptItems::isPostLikeListable()}): the type must be `show_in_rest`, its
 * REST controller must be exactly `WP_REST_Posts_Controller` (not a subclass), and
 * its collection route must expose a `GET` handler. Any other registered type is
 * rejected up-front with a stable `unsupported_post_type` (400) error, before route
 * resolution — instead of returning a misleading `total:0` (`wp_global_styles`) or
 * a differently-shaped collection (`attachment`, `wp_navigation`, templates). This
 * is because `show_in_rest` does not guarantee a post-like `GET <route>` summary:
 * subclasses such as global-styles, attachment, menu items, blocks, and font
 * families share the route shape but not the list contract.
 *
 * For a supported type it resolves the collection route via
 * `rest_get_route_for_post_type_items()` (honoring a custom `rest_namespace`) and
 * wraps `GET <route>` via `rest_do_request()`.
 *
 * @since 0.1.0
 */
final class ListCptItems implements Ability {

	/**
	 * Post types that use the base posts controller but are not plain listable posts.
	 *
	 * `wp_navigation` is served by the exact `WP_REST_Posts_Controller` and exposes a
	 * collection route, so the controller-class and route checks alone would admit it.
	 * A navigation menu is not a generic title/content/excerpt/status post, so it is
	 * excluded explicitly to keep this ability to plain post-like content.
	 *
	 * @var string[]
	 */
	private const NON_POST_LIKE_TYPES = array( 'wp_navigation' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/list-cpt-items';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Custom Post Type Items', 'abilities-catalog' ),
			'description'         => __( 'Lists items of a post-like REST post type with search, status, ordering, and pagination filters. Does not support font, global-styles, template, navigation, or attachment types.', 'abilities-catalog' ),
			'category'            => 'og-core-content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'The post type slug to list. Must be a post-like REST type (title/content/excerpt/status fields); font, global-styles, template, navigation, and attachment types are not supported. Use og-content/list-post-types to discover valid slugs.', 'abilities-catalog' ),
					),
					'search'    => array(
						'type'        => 'string',
						'description' => __( 'Limit results to those matching a search term.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'Limit results to a post status.', 'abilities-catalog' ),
					),
					'per_page'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 100,
						'default'     => 10,
						'description' => __( 'Number of items to return per page.', 'abilities-catalog' ),
					),
					'page'      => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => __( 'Page of the result set to return.', 'abilities-catalog' ),
					),
					'orderby'   => array(
						'type'        => 'string',
						'description' => __( 'Field to sort by.', 'abilities-catalog' ),
					),
					'order'     => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Sort direction.', 'abilities-catalog' ),
					),
					'context'   => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'post_type' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'items' ),
				'properties'           => array(
					'items'       => array(
						'type'        => 'array',
						'items'       => ContentListShaper::postItemSchema(),
						'description' => __( 'The list of items as flat summary rows. Use og-content/get-cpt-item for a single item body.', 'abilities-catalog' ),
					),
					'total'       => array(
						'type'        => 'integer',
						'description' => __( 'Total number of items matching the query.', 'abilities-catalog' ),
					),
					'total_pages' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of result pages available.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'keywords'     => array( 'search custom post type', 'list custom post type', 'find cpt items', 'list cpt entries', 'browse custom post type', 'cpt by status' ),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: `edit_posts` cap of the type for edit-context; otherwise
	 * any logged-in user.
	 *
	 * For an unknown or non-REST post type it returns true so `execute()` can
	 * surface the specific `invalid_post_type` (400) error instead of masking it as
	 * a permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may list the type's items.
	 */
	public function hasPermission( $input ): bool {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return true;
		}

		$context = $input['context'] ?? 'view';
		if ( 'edit' === $context ) {
			return current_user_can( $obj->cap->edit_posts );
		}

		return is_user_logged_in();
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The collection and totals, or an error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? (string) $input['post_type'] : '';

		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->show_in_rest ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'The requested post type does not exist or is not available in REST.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->isPostLikeListable( $post_type ) ) {
			return new WP_Error(
				'unsupported_post_type',
				__( 'The requested post type is not a post-like listable type. Font, global-styles, template, navigation, and attachment types use a different list contract and are not supported by this ability. Use the dedicated fonts/* and templates/* abilities for those.', 'abilities-catalog' ),
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

		$request = new WP_REST_Request( 'GET', $items_route );
		$request->set_param( 'context', $input['context'] ?? 'view' );

		if ( isset( $input['search'] ) ) {
			$request->set_param( 'search', (string) $input['search'] );
		}
		if ( isset( $input['status'] ) ) {
			$request->set_param( 'status', (string) $input['status'] );
		}
		if ( isset( $input['per_page'] ) ) {
			$request->set_param( 'per_page', absint( $input['per_page'] ) );
		}
		if ( isset( $input['page'] ) ) {
			$request->set_param( 'page', absint( $input['page'] ) );
		}
		if ( isset( $input['orderby'] ) ) {
			$request->set_param( 'orderby', (string) $input['orderby'] );
		}
		if ( isset( $input['order'] ) ) {
			$request->set_param( 'order', (string) $input['order'] );
		}

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$items   = rest_get_server()->response_to_data( $response, false );
		$headers = $response->get_headers();
		$rows    = is_array( $items ) ? array_map( array( ContentListShaper::class, 'postSummary' ), $items ) : array();

		return array(
			'items'       => $rows,
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		);
	}

	/**
	 * Tests whether a post type accepts a post-like collection read.
	 *
	 * A type passes only when all of the following hold:
	 *
	 * 1. It is registered and `show_in_rest`.
	 * 2. Its REST controller is exactly `WP_REST_Posts_Controller` — not a subclass.
	 *    Core subclasses (`WP_REST_Attachments_Controller`,
	 *    `WP_REST_Menu_Items_Controller`, `WP_REST_Blocks_Controller`,
	 *    `WP_REST_Global_Styles_Controller`, `WP_REST_Font_Families_Controller`,
	 *    `WP_REST_Font_Faces_Controller`) share the route shape but use a different
	 *    list contract, so an `instanceof` check would wrongly admit them.
	 *    `WP_REST_Templates_Controller` does not extend the posts controller at all.
	 * 3. Its registered collection route actually exposes a `GET` (READABLE) handler.
	 * 4. It is not in {@see ListCptItems::NON_POST_LIKE_TYPES} (e.g. `wp_navigation`,
	 *    which uses the base controller but is not a plain listable post).
	 *
	 * @param string $post_type The post type slug.
	 * @return bool True when the type accepts a post-like collection read.
	 */
	private function isPostLikeListable( string $post_type ): bool {
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

		return $this->hasCollectionReadRoute( $post_type );
	}

	/**
	 * Tests whether the type's REST collection route exposes a GET handler.
	 *
	 * @param string $post_type The post type slug.
	 * @return bool True when a READABLE handler is registered on the collection route.
	 */
	private function hasCollectionReadRoute( string $post_type ): bool {
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
			if ( ! empty( $methods['GET'] ) ) {
				return true;
			}
		}

		return false;
	}
}
