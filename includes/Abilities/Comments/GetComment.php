<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `comments/get-comment`.
 *
 * Wraps `GET /wp/v2/comments/<id>` via `rest_do_request()` and shapes the
 * response into a flat field set. Read-only; REST enforces per-object
 * visibility and edit-only fields underneath.
 *
 * @since 0.1.0
 */
final class GetComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'comments/get-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Comment', 'abilities-catalog' ),
			'description'         => __( 'Returns a single comment by ID, including its content, author, status, and link.', 'abilities-catalog' ),
			'category'            => 'comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'view', 'edit' ),
						'default'     => 'view',
						'description' => __( 'Scope of the request: "view" (public fields) or "edit" (includes author email for moderators).', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'post' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => __( 'The comment ID.', 'abilities-catalog' ),
					),
					'post'         => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the post the comment is on.', 'abilities-catalog' ),
					),
					'parent'       => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the parent comment, or 0 for a top-level comment.', 'abilities-catalog' ),
					),
					'author_name'  => array(
						'type'        => 'string',
						'description' => __( 'The display name of the comment author.', 'abilities-catalog' ),
					),
					'author_email' => array(
						'type'        => 'string',
						'description' => __( 'The author email address (edit context, moderators only).', 'abilities-catalog' ),
					),
					'content'      => array(
						'type'        => 'string',
						'description' => __( 'The rendered comment content.', 'abilities-catalog' ),
					),
					'status'       => array(
						'type'        => 'string',
						'description' => __( 'The comment status.', 'abilities-catalog' ),
					),
					'type'         => array(
						'type'        => 'string',
						'description' => __( 'The comment type.', 'abilities-catalog' ),
					),
					'date'         => array(
						'type'        => 'string',
						'description' => __( 'The comment date in site time.', 'abilities-catalog' ),
					),
					'link'         => array(
						'type'        => 'string',
						'description' => __( 'The public permalink to the comment.', 'abilities-catalog' ),
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
	 * Permission check: baseline `edit_posts` to read a comment.
	 *
	 * Encodes the catalog baseline capability for `comments/get-comment`.
	 * Object-level and edit-context visibility (`edit_comment`,
	 * `moderate_comments`) is enforced per object by REST; `edit_posts` is the
	 * minimum required to run the read and is not weaker than that baseline.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the comment.
	 */
	public function hasPermission( $input ): bool {
		$input = is_array( $input ) ? $input : array();

		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by dispatching the internal REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat comment fields, or the REST error.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$id      = absint( $input['id'] ?? 0 );
		$context = $input['context'] ?? 'view';

		$request = new WP_REST_Request( 'GET', '/wp/v2/comments/' . $id );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data( $response, false );

		return array(
			'id'           => (int) ( $data['id'] ?? $id ),
			'post'         => (int) ( $data['post'] ?? 0 ),
			'parent'       => (int) ( $data['parent'] ?? 0 ),
			'author_name'  => (string) ( $data['author_name'] ?? '' ),
			'author_email' => (string) ( $data['author_email'] ?? '' ),
			'content'      => (string) ( $data['content']['rendered'] ?? '' ),
			'status'       => (string) ( $data['status'] ?? '' ),
			'type'         => (string) ( $data['type'] ?? '' ),
			'date'         => (string) ( $data['date'] ?? '' ),
			'link'         => (string) ( $data['link'] ?? '' ),
		);
	}
}
