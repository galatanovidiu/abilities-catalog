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
 * Read ability: `og-content/list-post-types`.
 *
 * Wraps `GET /wp/v2/types` via the Abilities REST Adapter. The route returns an
 * object keyed by post-type slug (not a list), so the adapter passes the body
 * through unchanged and {@see shapeOutput()} normalises it into a list of objects
 * so `items` is an array. The bare route allows any logged-in user (and an
 * edit-capable user in edit context), which the catalog already matched, so a
 * `require_permission` floor mirrors that cap.
 *
 * @since 0.1.0
 */
final class ListPostTypes implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/list-post-types';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'              => '/wp/v2/types',
				'method'             => 'GET',
				'label'              => __( 'List Post Types', 'abilities-catalog' ),
				'description'        => __( 'Lists the REST-enabled post types registered on the site.', 'abilities-catalog' ),
				'category'           => 'og-core-content',
				'input_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'context' => array(
							'type'        => 'string',
							'enum'        => array( 'view', 'edit' ),
							'default'     => 'view',
							'description' => __( 'Scope of the request: "view" or "edit".', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'      => array(
					'type'                 => 'object',
					'required'             => array( 'items' ),
					'properties'           => array(
						'items'       => array(
							'type'        => 'array',
							'items'       => array(
								'type'                 => 'object',
								'required'             => array( 'slug', 'name' ),
								'properties'           => array(
									'slug'         => array(
										'type'        => 'string',
										'description' => __( 'The post type slug.', 'abilities-catalog' ),
									),
									'name'         => array(
										'type'        => 'string',
										'description' => __( 'The human-readable post type name.', 'abilities-catalog' ),
									),
									'hierarchical' => array(
										'type'        => 'boolean',
										'description' => __( 'Whether the type is hierarchical (like pages).', 'abilities-catalog' ),
									),
									'rest_base'    => array(
										'type'        => 'string',
										'description' => __( 'The REST base segment for this type\'s collection route; the namespace defaults to wp/v2 but a type may override it.', 'abilities-catalog' ),
									),
									'supports'     => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'Flat list of supported feature keys (e.g. title, editor, thumbnail).', 'abilities-catalog' ),
									),
									'taxonomies'   => array(
										'type'        => 'array',
										'items'       => array( 'type' => 'string' ),
										'description' => __( 'Taxonomy slugs associated with the type.', 'abilities-catalog' ),
									),
								),
								'additionalProperties' => false,
							),
							'description' => __( 'The list of post types.', 'abilities-catalog' ),
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of post types returned.', 'abilities-catalog' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of result pages available.', 'abilities-catalog' ),
						),
					),
					'additionalProperties' => false,
				),
				'require_permission' => array( $this, 'requirePermission' ),
				'output_callback'    => array( $this, 'shapeOutput' ),
				'meta'               => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission floor: for edit-context, the current user must be able to edit at
	 * least one REST-enabled post type (mirrors core's
	 * `WP_REST_Post_Types_Controller::get_items_permissions_check()`); otherwise any
	 * logged-in user.
	 *
	 * Mirrors the catalog's original cap. The route's own check still runs at
	 * dispatch.
	 *
	 * @param mixed $input The raw ability input.
	 * @return bool True to defer to the route's dispatch-time check.
	 */
	public function requirePermission( $input ): bool {
		$input   = is_array( $input ) ? $input : array();
		$context = $input['context'] ?? 'view';

		if ( 'edit' === $context ) {
			foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
				if ( current_user_can( $type->cap->edit_posts ) ) {
					return true;
				}
			}

			return false;
		}

		return is_user_logged_in();
	}

	/**
	 * Normalises the slug-keyed `/wp/v2/types` body into a flat list of objects.
	 *
	 * Wired as the adapter's `output_callback`; runs only on success. The route's
	 * body is an object keyed by slug, so it is iterated (not the collection
	 * envelope) and each type is flattened, enriching `supports` from the non-REST
	 * `get_all_post_type_supports()`. `$input` and `$response` are part of the
	 * callback signature but unused here.
	 *
	 * @param mixed               $data     The slug-keyed types object.
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The normalised list and totals.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$items = array();
		if ( is_array( $data ) ) {
			foreach ( $data as $slug => $type ) {
				if ( ! is_array( $type ) ) {
					continue;
				}

				$items[] = array(
					'slug'         => (string) ( $type['slug'] ?? $slug ),
					'name'         => (string) ( $type['name'] ?? '' ),
					'hierarchical' => (bool) ( $type['hierarchical'] ?? false ),
					'rest_base'    => (string) ( $type['rest_base'] ?? '' ),
					'supports'     => array_keys( get_all_post_type_supports( (string) ( $type['slug'] ?? $slug ) ) ),
					'taxonomies'   => isset( $type['taxonomies'] ) && is_array( $type['taxonomies'] ) ? array_values( $type['taxonomies'] ) : array(),
				);
			}
		}

		return array(
			'items'       => $items,
			'total'       => count( $items ),
			'total_pages' => $items ? 1 : 0,
		);
	}
}
