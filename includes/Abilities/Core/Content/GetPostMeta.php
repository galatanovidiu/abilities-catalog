<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\PostMetaKeys;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `content/get-post-meta`.
 *
 * Reads a post's custom fields (meta), limited to the meta keys the site has
 * registered with `show_in_rest` for that post type — the same set the REST API
 * exposes. It never returns arbitrary or internal meta. Wraps core
 * `get_post_meta()`; the registered-key gate runs through
 * {@see PostMetaKeys::forPostType()}. Use `content/list-post-meta-keys` first to
 * discover which keys a post type supports.
 *
 * @since 0.5.0
 */
final class GetPostMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'content/get-post-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Post Meta', 'abilities-catalog' ),
			'description'         => __( 'Returns a post\'s custom fields (meta) as a key/value map, restricted to the meta keys registered with show_in_rest for the post type. Use content/list-post-meta-keys to discover supported keys.', 'abilities-catalog' ),
			'category'            => 'content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post ID to read meta from.', 'abilities-catalog' ),
					),
					'keys' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Optional list of meta keys to return. When omitted, all registered show_in_rest keys are returned.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'meta' ),
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The post ID.', 'abilities-catalog' ),
					),
					'meta' => array(
						'type'        => 'object',
						'description' => __( 'Map of meta key to value. Single-value keys return a scalar; multi-value keys return an array.', 'abilities-catalog' ),
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
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: edit access to the target post (object-level).
	 *
	 * Meta can carry data beyond the public post fields, so reading it requires
	 * `edit_post` on the object rather than mere read access.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the post's meta.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? absint( $input['id'] ) : 0;

		if ( $id <= 0 ) {
			return false;
		}

		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Executes the ability by reading registered meta for the post.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post id and meta map, or an error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] );
		$post  = get_post( $id );

		if ( ! $post ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'abilities-catalog' ), array( 'status' => 404 ) );
		}

		$allowed   = PostMetaKeys::forPostType( $post->post_type );
		$requested = ! empty( $input['keys'] ) && is_array( $input['keys'] )
			? array_map( 'strval', $input['keys'] )
			: array_keys( $allowed );

		$meta = array();
		foreach ( $requested as $key ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$meta[ $key ] = get_post_meta( $id, $key, $allowed[ $key ]['single'] );
		}

		return array(
			'id'   => $id,
			'meta' => (object) $meta,
		);
	}
}
