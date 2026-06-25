<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed T1 read ability: `og-dashboard/get-activity`.
 *
 * Returns a subset of the wp-admin "Activity" dashboard widget: the most
 * recently published posts and the most recent approved comments. Built directly
 * on core query functions (`get_posts()`, `get_comments()`) rather than REST,
 * since this is a net-new composed read with no single REST equivalent.
 *
 * @since 0.1.0
 */
final class GetActivity implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-dashboard/get-activity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Activity', 'abilities-catalog' ),
			'description'         => __( 'Returns recent dashboard activity: recently published posts and recent approved comments.', 'abilities-catalog' ),
			'category'            => 'og-core-dashboard',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'number' => array(
						'type'        => 'integer',
						'default'     => 5,
						'minimum'     => 1,
						'maximum'     => 20,
						'description' => __( 'Maximum number of items to return for each list (1-20).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'published', 'comments' ),
				'properties'           => array(
					'published' => array(
						'type'        => 'array',
						'description' => __( 'Recently published posts.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'title', 'date' ),
							'properties'           => array(
								'id'    => array(
									'type'        => 'integer',
									'description' => __( 'Post ID.', 'abilities-catalog' ),
								),
								'title' => array(
									'type'        => 'string',
									'description' => __( 'Post title.', 'abilities-catalog' ),
								),
								'date'  => array(
									'type'        => 'string',
									'description' => __( 'Publish date (site timezone).', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'comments'  => array(
						'type'        => 'array',
						'description' => __( 'Recent approved comments.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'post', 'author', 'date', 'excerpt' ),
							'properties'           => array(
								'id'      => array(
									'type'        => 'integer',
									'description' => __( 'Comment ID.', 'abilities-catalog' ),
								),
								'post'    => array(
									'type'        => 'integer',
									'description' => __( 'ID of the post the comment belongs to.', 'abilities-catalog' ),
								),
								'author'  => array(
									'type'        => 'string',
									'description' => __( 'Comment author name.', 'abilities-catalog' ),
								),
								'date'    => array(
									'type'        => 'string',
									'description' => __( 'Comment date (site timezone).', 'abilities-catalog' ),
								),
								'excerpt' => array(
									'type'        => 'string',
									'description' => __( 'Trimmed comment content.', 'abilities-catalog' ),
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
				'annotations'       => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the current user may edit posts.
	 *
	 * Encodes the catalog capability for `og-dashboard/get-activity` (`edit_posts`).
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit posts.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by reading recent posts and comments.
	 *
	 * Comments pass through the same visibility gate wp-admin applies: a user
	 * who cannot edit the parent post does not see comments on posts that are
	 * password-protected or that they cannot read.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The recent activity lists.
	 */
	public function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$number = isset( $input['number'] ) ? (int) $input['number'] : 5;
		$number = max( 1, min( 20, $number ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- 'suppress_filters' => false keeps the query cacheable, which the sniff documents as safe.
		$recent_published = get_posts(
			array(
				'post_status'      => 'publish',
				'numberposts'      => $number,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$published = array();
		foreach ( $recent_published as $post ) {
			$published[] = array(
				'id'    => (int) $post->ID,
				'title' => (string) get_the_title( $post->ID ),
				'date'  => (string) $post->post_date,
			);
		}

		// Mirror core's wp-admin Activity widget visibility gate
		// (wp-admin/includes/dashboard.php wp_dashboard_recent_comments()): hide
		// comments on posts the user cannot edit when the post is password-protected
		// or unreadable by them. Over-fetch and refill so the requested count is
		// still honored after the gate removes hidden comments.
		$comments       = array();
		$comments_count = 0;
		$query_args     = array(
			'number' => $number * 5,
			'offset' => 0,
			'status' => 'approve',
		);

		do {
			$possible = get_comments( $query_args );
			if ( empty( $possible ) || ! is_array( $possible ) ) {
				break;
			}

			foreach ( $possible as $comment ) {
				if ( ! $comment instanceof \WP_Comment ) {
					continue;
				}

				$post_id = (int) $comment->comment_post_ID;
				if ( ! current_user_can( 'edit_post', $post_id )
					&& ( post_password_required( $post_id )
						|| ! current_user_can( 'read_post', $post_id ) )
				) {
					// No access to the parent post: hide its comments, as wp-admin does.
					continue;
				}

				$comments[]     = array(
					'id'      => (int) $comment->comment_ID,
					'post'    => $post_id,
					'author'  => (string) $comment->comment_author,
					'date'    => (string) $comment->comment_date,
					'excerpt' => (string) wp_trim_words( (string) $comment->comment_content ),
				);
				$comments_count = count( $comments );

				if ( $comments_count === $number ) {
					break 2;
				}
			}

			$query_args['offset'] += $query_args['number'];
			$query_args['number']  = $number * 10;
		} while ( $comments_count < $number );

		return array(
			'published' => $published,
			'comments'  => $comments,
		);
	}
}
