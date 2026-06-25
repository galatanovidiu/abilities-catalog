<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Comments;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `og-comments/get-comment-count`.
 *
 * Wraps `wp_count_comments()` and projects its status buckets into a flat,
 * closed result. No REST route covers this read, so the ability calls the core
 * function directly. `wp_count_comments()` performs no capability check and a
 * filter may return a plain array instead of the documented stdClass, so the
 * result is cast `(array)` and each key is read defensively with an integer
 * cast and a `0` fallback. The hyphenated `post-trashed` key is renamed to the
 * schema-safe `post_trashed`.
 *
 * @since 0.1.0
 */
final class GetCommentCount implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-comments/get-comment-count';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Comment Count', 'abilities-catalog' ),
			'description'         => __( 'Returns comment counts grouped by status for one post or the whole site: approved, moderated (awaiting moderation), spam, trash, post_trashed (comments on trashed posts), total_comments (non-trashed, including spam), and all (pending plus approved). Pass post_id to scope the counts to a single post; omit it (or pass 0) for site-wide totals. Whole-site counts require the moderate_comments capability; a single post\'s counts are visible to that post\'s editor.', 'abilities-catalog' ),
			'category'            => 'og-core-comments',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'The post ID to scope the counts to. Omit or pass 0 for whole-site totals. Discover IDs with og-content/list-posts or og-content/list-pages.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'post_id', 'approved', 'moderated', 'spam', 'trash', 'post_trashed', 'total_comments', 'all' ),
				'properties'           => array(
					'post_id'        => array(
						'type'        => 'integer',
						'description' => __( 'The post ID the counts are scoped to; 0 means the whole site.', 'abilities-catalog' ),
					),
					'approved'       => array(
						'type'        => 'integer',
						'description' => __( 'Number of approved (published) comments.', 'abilities-catalog' ),
					),
					'moderated'      => array(
						'type'        => 'integer',
						'description' => __( 'Number of comments awaiting moderation (the pending/"hold" queue).', 'abilities-catalog' ),
					),
					'spam'           => array(
						'type'        => 'integer',
						'description' => __( 'Number of comments marked as spam.', 'abilities-catalog' ),
					),
					'trash'          => array(
						'type'        => 'integer',
						'description' => __( 'Number of comments in the Trash.', 'abilities-catalog' ),
					),
					'post_trashed'   => array(
						'type'        => 'integer',
						'description' => __( 'Number of comments whose post is in the Trash.', 'abilities-catalog' ),
					),
					'total_comments' => array(
						'type'        => 'integer',
						'description' => __( 'Total non-trashed comments, including spam.', 'abilities-catalog' ),
					),
					'all'            => array(
						'type'        => 'integer',
						'description' => __( 'Total pending plus approved comments.', 'abilities-catalog' ),
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
	 * Conditional permission check by scope.
	 *
	 * Spam, trash, and moderation counts are moderation data. For a single post
	 * the caller may read its counts if they can edit that post or moderate
	 * globally; for the whole site, only a global moderator may read the totals.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the requested counts.
	 */
	public function hasPermission( $input ): bool {
		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( $post_id > 0 ) {
			return current_user_can( 'edit_post', $post_id ) || current_user_can( 'moderate_comments' );
		}

		return current_user_can( 'moderate_comments' );
	}

	/**
	 * Executes the ability by reading the cached comment counts.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,int>|\WP_Error The flat status buckets, or a 404 for a missing post.
	 */
	public function execute( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = absint( $input['post_id'] ?? 0 );

		if ( $post_id > 0 && ! get_post( $post_id ) ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$counts = (array) wp_count_comments( $post_id );

		return array(
			'post_id'        => $post_id,
			'approved'       => (int) ( $counts['approved'] ?? 0 ),
			'moderated'      => (int) ( $counts['moderated'] ?? 0 ),
			'spam'           => (int) ( $counts['spam'] ?? 0 ),
			'trash'          => (int) ( $counts['trash'] ?? 0 ),
			'post_trashed'   => (int) ( $counts['post-trashed'] ?? 0 ),
			'total_comments' => (int) ( $counts['total_comments'] ?? 0 ),
			'all'            => (int) ( $counts['all'] ?? 0 ),
		);
	}
}
