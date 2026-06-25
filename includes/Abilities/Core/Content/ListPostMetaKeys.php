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
 * T1 read ability: `og-content/list-post-meta-keys`.
 *
 * Lists the custom-field (meta) keys a post type supports — those registered with
 * `show_in_rest` — so an agent knows what `og-content/get-post-meta`,
 * `og-content/update-post-meta`, and `og-content/delete-post-meta` can act on. Each key
 * includes its declared type, whether it stores a single value or a list, and its
 * description. Wraps core `get_registered_meta_keys()` via
 * {@see PostMetaKeys::forPostType()}.
 *
 * @since 0.5.0
 */
final class ListPostMetaKeys implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-content/list-post-meta-keys';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Post Meta Keys', 'abilities-catalog' ),
			'description'         => __( 'Returns the registered show_in_rest meta keys for a post type, with each key\'s type, single/list shape, and description. Use this to discover which custom fields the post-meta abilities operate on; read and write permission is still enforced per key by those abilities.', 'abilities-catalog' ),
			'category'            => 'og-core-content',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'default'     => 'post',
						'description' => __( 'The post type to inspect (e.g. "post", "page", or a custom post type).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'post_type', 'keys' ),
				'properties'           => array(
					'post_type' => array(
						'type'        => 'string',
						'description' => __( 'The inspected post type.', 'abilities-catalog' ),
					),
					'keys'      => array(
						'type'        => 'array',
						'description' => __( 'The registered show_in_rest meta keys for the post type.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'key', 'type', 'single' ),
							'properties'           => array(
								'key'         => array(
									'type'        => 'string',
									'description' => __( 'The public meta key (the show_in_rest alias when one is registered, otherwise the storage key).', 'abilities-catalog' ),
								),
								'type'        => array(
									'type'        => 'string',
									'description' => __( 'The declared value type (string, integer, number, boolean, array, object).', 'abilities-catalog' ),
								),
								'single'      => array(
									'type'        => 'boolean',
									'description' => __( 'True if the key stores a single value; false if it stores a list.', 'abilities-catalog' ),
								),
								'description' => array(
									'type'        => 'string',
									'description' => __( 'The registered human-readable description, if any.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
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
	 * Permission check: edit access to the requested post type.
	 *
	 * Meta keys are an editing concern, so the guard requires the post type's
	 * `edit_posts` capability. For an unregistered post type it returns true so
	 * `execute()` can surface the specific `invalid_post_type` (400) error instead
	 * of masking it as a permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may inspect the post type's meta keys.
	 */
	public function hasPermission( $input ): bool {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';

		$object = get_post_type_object( $post_type );
		if ( ! $object ) {
			return true;
		}

		return current_user_can( $object->cap->edit_posts );
	}

	/**
	 * Executes the ability by listing registered meta keys for the post type.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The post type and its registered meta keys, or an error.
	 */
	public function execute( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$post_type = isset( $input['post_type'] ) ? sanitize_key( (string) $input['post_type'] ) : 'post';

		if ( ! get_post_type_object( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				__( 'The requested post type does not exist.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$keys = array();
		foreach ( PostMetaKeys::forPostType( $post_type ) as $key => $shape ) {
			$keys[] = array(
				'key'         => $key,
				'type'        => $shape['type'],
				'single'      => $shape['single'],
				'description' => $shape['description'],
			);
		}

		return array(
			'post_type' => $post_type,
			'keys'      => $keys,
		);
	}
}
