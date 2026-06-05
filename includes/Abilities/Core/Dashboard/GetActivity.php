<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Dashboard;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed T1 read ability: `dashboard/get-activity`.
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
		return 'dashboard/get-activity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Activity', 'abilities-catalog' ),
			'description'         => __( 'Returns recent dashboard activity: recently published posts and recent approved comments.', 'abilities-catalog' ),
			'category'            => 'dashboard',
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
	 * Permission check: the current user may edit posts.
	 *
	 * Encodes the catalog capability for `dashboard/get-activity` (`edit_posts`).
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

		$recent_comments = get_comments(
			array(
				'number' => $number,
				'status' => 'approve',
			)
		);
		$recent_comments = is_array( $recent_comments ) ? $recent_comments : array();

		$comments = array();
		foreach ( $recent_comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$comments[] = array(
				'id'      => (int) $comment->comment_ID,
				'post'    => (int) $comment->comment_post_ID,
				'author'  => (string) $comment->comment_author,
				'date'    => (string) $comment->comment_date,
				'excerpt' => (string) wp_trim_words( (string) $comment->comment_content ),
			);
		}

		return array(
			'published' => $published,
			'comments'  => $comments,
		);
	}
}
