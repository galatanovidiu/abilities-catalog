<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Templates;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `templates/create-pattern`.
 *
 * Wraps `POST /wp/v2/blocks` via `rest_do_request()`. A user pattern is a
 * `wp_block` post (a reusable block / synced pattern). The permission mirrors
 * the `WP_REST_Blocks_Controller` (which extends `WP_REST_Posts_Controller`):
 * `create_item_permissions_check()` requires the post type's `create_posts`
 * capability. For `wp_block` that capability is mapped to `publish_posts`
 * (see the `capabilities` array in the `wp_block` registration in
 * `wp-includes/post.php`), so creating ANY pattern — even a draft — requires
 * `publish_posts`. Publishing a pattern (status publish/future/private) also
 * requires `publish_posts` via `handle_status_param()`, which is already
 * subsumed by the create check.
 *
 * Write annotations (`readonly:false, destructive:false, idempotent:false`) so
 * the run controller routes the call as POST. The REST route re-checks the
 * capability and sanitizes the content (defense in depth).
 *
 * @since 0.2.0
 */
final class CreatePattern implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'templates/create-pattern';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Pattern', 'abilities-catalog' ),
			'description'         => __( 'Creates a new user pattern (reusable block, post type "wp_block"). Publishes by default; set status to "draft" to keep it unpublished. Returns edit_link (the Site Editor URL) — surface it so a human can open and finish the pattern. Requires the publish capability.', 'abilities-catalog' ),
			'category'            => 'templates',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'   => array(
						'type'        => 'string',
						'description' => __( 'The pattern title.', 'abilities-catalog' ),
					),
					'content' => array(
						'type'        => 'string',
						'description' => __( 'The pattern block markup (serialized blocks; sanitized by WordPress).', 'abilities-catalog' ),
					),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array( 'draft', 'publish' ),
						'default'     => 'publish',
						'description' => __( 'The pattern status. Defaults to "publish".', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'title', 'content' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'status', 'link' ),
				'properties'           => array(
					'id'        => array(
						'type'        => 'integer',
						'description' => __( 'The new pattern (wp_block) post ID.', 'abilities-catalog' ),
					),
					'title'     => array(
						'type'        => 'string',
						'description' => __( 'The resulting pattern title.', 'abilities-catalog' ),
					),
					'status'    => array(
						'type'        => 'string',
						'description' => __( 'The resulting pattern status.', 'abilities-catalog' ),
					),
					'link'      => array(
						'type'        => 'string',
						'description' => __( 'The pattern permalink.', 'abilities-catalog' ),
					),
					'edit_link' => array(
						'type'        => 'string',
						'description' => __( 'The Site Editor URL where a human can open and edit the new pattern.', 'abilities-catalog' ),
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
				'screen'       => 'site-editor.php',
			),
		);
	}

	/**
	 * Permission check mirroring the blocks controller's create check.
	 *
	 * Requires the `wp_block` post type's `create_posts` capability, which the
	 * post type maps to `publish_posts`. Resolved dynamically from the post type
	 * object so the gate is never weaker than the wrapped REST route.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may create the requested pattern.
	 */
	public function hasPermission( $input ): bool {
		$post_type = get_post_type_object( 'wp_block' );
		if ( null === $post_type ) {
			return false;
		}

		return current_user_can( $post_type->cap->create_posts );
	}

	/**
	 * Executes the ability by dispatching the internal REST create request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new pattern's id, title, status, link, edit_link, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$request = new WP_REST_Request( 'POST', '/wp/v2/blocks' );

		// String fields pass through to the REST route, which sanitizes them
		// (content via wp_kses_post, etc.).
		foreach ( array( 'title', 'content' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || '' === $input[ $field ] ) {
				continue;
			}

			$request->set_param( $field, (string) $input[ $field ] );
		}

		// Inject the declared "publish" default when status is omitted/empty: the
		// Abilities API only applies top-level schema defaults, not nested property
		// ones, so without this core would silently fall back to "draft".
		$status = isset( $input['status'] ) && '' !== $input['status']
			? sanitize_key( (string) $input['status'] )
			: 'publish';
		$request->set_param( 'status', $status );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );

		// The blocks controller exposes title.raw (it unsets title.rendered), so
		// read raw first to avoid an always-empty title.
		$title = $data['title'] ?? '';
		if ( is_array( $title ) ) {
			$title = $title['raw'] ?? ( $title['rendered'] ?? '' );
		}

		$id        = (int) ( $data['id'] ?? 0 );
		$edit_link = $id > 0
			? admin_url( 'site-editor.php?postType=wp_block&postId=' . rawurlencode( (string) $id ) . '&canvas=edit' )
			: '';

		return array(
			'id'        => $id,
			'title'     => (string) $title,
			'status'    => (string) ( $data['status'] ?? '' ),
			'link'      => (string) ( $data['link'] ?? '' ),
			'edit_link' => $edit_link,
		);
	}
}
