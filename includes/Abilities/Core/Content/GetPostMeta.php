<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Content;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\PostAccess;
use GalatanOvidiu\AbilitiesCatalog\Support\PostMetaKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-content/get-post-meta`.
 *
 * Reads a post's custom fields (meta), limited to the meta keys the site has
 * registered with `show_in_rest` for that post type — the same set the REST API
 * exposes. It never returns arbitrary or internal meta. Wraps core
 * `get_post_meta()`; the registered-key gate runs through
 * {@see PostMetaKeys::forPostType()}. Use `og-content/list-post-meta-keys` first to
 * discover which keys a post type supports.
 *
 * @since 0.5.0
 */
final class GetPostMeta implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/get-post-meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Post Meta', 'abilities-catalog' ),
			'description'         => __( 'Returns a post\'s custom fields (meta) as a key/value map, restricted to the meta keys registered with show_in_rest for the post type. Requires edit access to the post (editor-only). Use og-content/list-post-meta-keys to discover supported keys.', 'abilities-catalog' ),
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
						'description' => __( 'Optional list of meta keys to return. When omitted or empty, all registered show_in_rest keys are returned. Requested keys that are not registered for the post type are silently skipped.', 'abilities-catalog' ),
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
						'description' => __( 'Map of meta key to value. Single-value keys return one value (a scalar, array, or object, depending on the registered meta type); multi-value keys return an array of values.', 'abilities-catalog' ),
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
	 * Permission check: delegated to `execute()`.
	 *
	 * Meta can carry data beyond the public post fields, so reading it requires
	 * object-level `edit_post`. This ability calls core directly (no wrapped REST
	 * route), so the object-level capability is enforced in `execute()` via
	 * {@see PostAccess::resolveEditable()} — which returns `rest_post_invalid_id`
	 * (404) for a missing post and `rest_cannot_edit` (403) when the user may not
	 * edit it, instead of masking both as a single permission error.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Always true; `execute()` is the server-side guard.
	 */
	public function hasPermission( $input ): bool {
		return true;
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
		$post  = PostAccess::resolveEditable( $id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$allowed   = PostMetaKeys::forPostType( $post->post_type );
		$requested = ! empty( $input['keys'] ) && is_array( $input['keys'] )
			? array_map( 'strval', $input['keys'] )
			: array_keys( $allowed );

		$meta = array();
		foreach ( $requested as $name ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}

			$shape         = $allowed[ $name ];
			$raw           = get_post_meta( $id, $shape['storage_key'], $shape['single'] );
			$meta[ $name ] = PostMetaKeys::castForResponse( $raw, $shape );
		}

		return array(
			'id'   => $id,
			'meta' => (object) $meta,
		);
	}
}
