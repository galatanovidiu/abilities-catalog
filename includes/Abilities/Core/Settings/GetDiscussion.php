<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-settings/get-discussion`.
 *
 * Returns the Discussion Settings screen values, read directly from options.
 * Net-new read: no REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetDiscussion implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/get-discussion';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Discussion Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the main Discussion Settings: comment and ping defaults, moderation rules, notification flags, and avatar settings.', 'abilities-catalog' ),
			'category'            => 'og-core-settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'default_comment_status' ),
				'properties'           => array(
					'default_comment_status'       => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => __( 'Default comment status for new posts ("open" or "closed").', 'abilities-catalog' ),
					),
					'default_ping_status'          => array(
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'description' => __( 'Default pingback/trackback status for new posts ("open" or "closed").', 'abilities-catalog' ),
					),
					'comment_registration'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether users must be registered and logged in to comment.', 'abilities-catalog' ),
					),
					'close_comments_for_old_posts' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether comments close automatically on old posts.', 'abilities-catalog' ),
					),
					'close_comments_days_old'      => array(
						'type'        => 'integer',
						'description' => __( 'Number of days after which comments close.', 'abilities-catalog' ),
					),
					'comments_per_page'            => array(
						'type'        => 'integer',
						'description' => __( 'Number of top-level comments shown per page.', 'abilities-catalog' ),
					),
					'default_comments_page'        => array(
						'type'        => 'string',
						'enum'        => array( 'newest', 'oldest' ),
						'description' => __( 'Which comments page is shown by default ("newest" or "oldest").', 'abilities-catalog' ),
					),
					'comment_order'                => array(
						'type'        => 'string',
						'enum'        => array( 'asc', 'desc' ),
						'description' => __( 'Order comments are displayed in ("asc" or "desc").', 'abilities-catalog' ),
					),
					'comment_moderation'           => array(
						'type'        => 'boolean',
						'description' => __( 'Whether comments must be manually approved.', 'abilities-catalog' ),
					),
					'comment_max_links'            => array(
						'type'        => 'integer',
						'description' => __( 'Number of links in a comment that triggers the moderation queue.', 'abilities-catalog' ),
					),
					'moderation_notify'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to email the admin when a comment is held for moderation.', 'abilities-catalog' ),
					),
					'comments_notify'              => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to email the admin when anyone posts a comment.', 'abilities-catalog' ),
					),
					'show_avatars'                 => array(
						'type'        => 'boolean',
						'description' => __( 'Whether avatars are displayed.', 'abilities-catalog' ),
					),
					'avatar_rating'                => array(
						'type'        => 'string',
						'enum'        => array( 'G', 'PG', 'R', 'X' ),
						'description' => __( 'Maximum avatar rating to display (e.g. "G").', 'abilities-catalog' ),
					),
					'avatar_default'               => array(
						'type'        => 'string',
						'description' => __( 'The default avatar style for users without a custom avatar.', 'abilities-catalog' ),
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
	 * Permission check: the current user may manage options.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by reading discussion settings directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The discussion settings fields.
	 */
	public function execute( $input = null ) {
		return array(
			'default_comment_status'       => (string) ( get_option( 'default_comment_status' ) ?? '' ),
			'default_ping_status'          => (string) ( get_option( 'default_ping_status' ) ?? '' ),
			'comment_registration'         => (bool) get_option( 'comment_registration' ),
			'close_comments_for_old_posts' => (bool) get_option( 'close_comments_for_old_posts' ),
			'close_comments_days_old'      => absint( get_option( 'close_comments_days_old' ) ),
			'comments_per_page'            => absint( get_option( 'comments_per_page' ) ),
			'default_comments_page'        => (string) ( get_option( 'default_comments_page' ) ?? '' ),
			'comment_order'                => (string) ( get_option( 'comment_order' ) ?? '' ),
			'comment_moderation'           => (bool) get_option( 'comment_moderation' ),
			'comment_max_links'            => absint( get_option( 'comment_max_links' ) ),
			'moderation_notify'            => (bool) get_option( 'moderation_notify' ),
			'comments_notify'              => (bool) get_option( 'comments_notify' ),
			'show_avatars'                 => (bool) get_option( 'show_avatars' ),
			'avatar_rating'                => (string) ( get_option( 'avatar_rating' ) ?? '' ),
			'avatar_default'               => (string) get_option( 'avatar_default', 'mystery' ),
		);
	}
}
