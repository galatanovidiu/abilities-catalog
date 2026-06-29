<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesRestAdapter\Rest_Route_Ability;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-comments/get-comment`.
 *
 * Wraps `GET /wp/v2/comments/<id>` via the Abilities REST Adapter. The input
 * schema is DERIVED from the route — the path capture `id` plus the route's query
 * args, including `context` (`view`/`edit`), where `edit` surfaces `author_email`
 * to moderators. The output is OVERRIDDEN to the catalog's flat field set through
 * {@see shapeOutput()}. Permission delegates to the route's own check (no
 * `require_permission` floor is set), so visibility follows REST, not the
 * catalog's old `edit_posts` baseline.
 *
 * @since 0.1.0
 */
final class GetComment implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/get-comment';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return Rest_Route_Ability::build_args(
			$this->name(),
			array(
				'route'           => '/wp/v2/comments/(?P<id>[\d]+)',
				'method'          => 'GET',
				'label'           => __( 'Get Comment', 'abilities-catalog' ),
				'description'     => __( 'Returns a single comment by ID, including its content, author, status, and link.', 'abilities-catalog' ),
				'category'        => 'og-core-comments',
				'output_schema'   => array(
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
				'output_callback' => array( $this, 'shapeOutput' ),
				'meta'            => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Flattens the REST comment body to the catalog's 10-field set.
	 *
	 * Wired as the adapter's `output_callback`, so it runs only on success, over the
	 * REST comment body. `content` is un-nested from its `{ rendered: ... }` shape;
	 * the other fields copy across with a type cast and a safe default, so a value
	 * REST omits for the caller's context (e.g. `author_email` outside `edit`) comes
	 * back as `''` rather than a missing key. `$input` and `$response` are part of the
	 * callback signature but unused here — the body carries everything this shape needs.
	 *
	 * @param mixed               $data     The REST comment body (associative array).
	 * @param array<string,mixed> $input    The original ability input. Unused.
	 * @param \WP_REST_Response   $response The REST response. Unused.
	 * @return array<string,mixed> The flat comment fields.
	 */
	public function shapeOutput( $data, array $input, WP_REST_Response $response ): array {
		$data = is_array( $data ) ? $data : array();

		return array(
			'id'           => (int) ( $data['id'] ?? 0 ),
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
