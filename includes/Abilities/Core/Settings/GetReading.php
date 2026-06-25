<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-settings/get-reading`.
 *
 * Returns the stored Reading Settings option values, read directly from options.
 * These normally match the Reading Settings screen, but the screen's own
 * normalization guard for `show_on_front` is not re-run here. Net-new read: no
 * REST route is dispatched.
 *
 * @since 0.1.0
 */
final class GetReading implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/get-reading';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Reading Settings', 'abilities-catalog' ),
			'description'         => __( 'Returns the stored Reading Settings option values (which normally match the Reading Settings screen): front page display, front and posts page IDs, posts per page and per RSS feed, and the search-engine visibility flag.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'blog_public' ),
				'properties'           => array(
					'show_on_front'  => array(
						'type'        => 'string',
						'enum'        => array( 'posts', 'page' ),
						'description' => __( 'What to show on the front page: "posts" or "page".', 'abilities-catalog' ),
					),
					'page_on_front'  => array(
						'type'        => 'integer',
						'description' => __( 'The page ID used as the static front page. 0 means no page is assigned.', 'abilities-catalog' ),
					),
					'page_for_posts' => array(
						'type'        => 'integer',
						'description' => __( 'The page ID used to display the latest posts. 0 means no page is assigned.', 'abilities-catalog' ),
					),
					'posts_per_page' => array(
						'type'        => 'integer',
						'description' => __( 'The number of blog posts shown per page.', 'abilities-catalog' ),
					),
					'posts_per_rss'  => array(
						'type'        => 'integer',
						'description' => __( 'The number of most recent items shown in syndication feeds.', 'abilities-catalog' ),
					),
					'blog_public'    => array(
						'type'        => 'boolean',
						'description' => __( 'Whether search engines are allowed to index the site.', 'abilities-catalog' ),
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
	 * Executes the ability by reading reading settings directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The reading settings fields.
	 */
	public function execute( $input = null ) {
		return array(
			'show_on_front'  => (string) ( get_option( 'show_on_front' ) ?? '' ),
			'page_on_front'  => absint( get_option( 'page_on_front' ) ),
			'page_for_posts' => absint( get_option( 'page_for_posts' ) ),
			// Plain (int), not absint: posts_per_page / posts_per_rss accept the -1
			// "show all" sentinel, and the write path preserves it. absint() would
			// collapse a stored -1 to 1, misreporting the setting.
			'posts_per_page' => (int) get_option( 'posts_per_page' ),
			'posts_per_rss'  => (int) get_option( 'posts_per_rss' ),
			'blog_public'    => (bool) get_option( 'blog_public' ),
		);
	}
}
