<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Settings;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\BooleanInput;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T2 non-destructive write ability: `og-settings/update-reading`.
 *
 * Updates the Reading Settings screen. The accepted fields mirror the matching
 * read ability {@see GetReading}. The write path is split by where each option is
 * exposed:
 *
 * - `show_on_front`, `page_on_front`, `page_for_posts`, `posts_per_page` are in
 *   the core REST settings registry, so they are written via `POST /wp/v2/settings`.
 * - `posts_per_rss` and `blog_public` are not REST-registered, so they are written
 *   directly with `update_option()` after the capability check and per-type
 *   sanitization.
 *
 * @since 0.3.0
 */
final class UpdateReading implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-settings/update-reading';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Reading Settings', 'abilities-catalog' ),
			'description'         => __( 'Updates Reading Settings: front page display, front and posts page IDs, posts per page and per RSS feed, and the search-engine visibility flag.', 'abilities-catalog' ),
			'category'            => 'settings',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'show_on_front'  => array(
						'type'        => 'string',
						'enum'        => array( 'posts', 'page' ),
						'description' => __( 'What to show on the front page: "posts" or "page".', 'abilities-catalog' ),
					),
					'page_on_front'  => array(
						'type'        => 'integer',
						'description' => __( 'The page ID used as the static front page.', 'abilities-catalog' ),
					),
					'page_for_posts' => array(
						'type'        => 'integer',
						'description' => __( 'The page ID used to display the latest posts.', 'abilities-catalog' ),
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
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'blog_public' ),
				'properties'           => array(
					'show_on_front'  => array(
						'type'        => 'string',
						'description' => __( 'The resulting front page display setting.', 'abilities-catalog' ),
					),
					'page_on_front'  => array(
						'type'        => 'integer',
						'description' => __( 'The resulting static front page ID.', 'abilities-catalog' ),
					),
					'page_for_posts' => array(
						'type'        => 'integer',
						'description' => __( 'The resulting posts page ID.', 'abilities-catalog' ),
					),
					'posts_per_page' => array(
						'type'        => 'integer',
						'description' => __( 'The resulting number of posts per page.', 'abilities-catalog' ),
					),
					'posts_per_rss'  => array(
						'type'        => 'integer',
						'description' => __( 'The resulting number of items per RSS feed.', 'abilities-catalog' ),
					),
					'blog_public'    => array(
						'type'        => 'boolean',
						'description' => __( 'The resulting search-engine visibility flag.', 'abilities-catalog' ),
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
				'screen'       => 'options-reading.php',
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
	 * Executes the ability by writing the Reading Settings.
	 *
	 * REST-registered keys go through `POST /wp/v2/settings`; the non-registered
	 * keys (`posts_per_rss`, `blog_public`) go through `update_option()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The resulting reading settings, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		// Defense in depth: update_option() does not re-check the capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'abilities_catalog_forbidden',
				__( 'You are not allowed to update reading settings.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$request  = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$has_rest = false;

		if ( array_key_exists( 'show_on_front', $input ) ) {
			$show_on_front = sanitize_text_field( (string) $input['show_on_front'] );

			if ( ! in_array( $show_on_front, array( 'posts', 'page' ), true ) ) {
				return new \WP_Error(
					'abilities_catalog_invalid_show_on_front',
					__( 'The front page display must be "posts" or "page".', 'abilities-catalog' ),
					array( 'status' => 400 )
				);
			}

			$request->set_param( 'show_on_front', $show_on_front );
			$has_rest = true;
		}

		foreach ( array( 'page_on_front', 'page_for_posts', 'posts_per_page' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			// posts_per_page passes through as a plain int so core's sanitizer
			// preserves the -1 ("show all") sentinel; absint() would corrupt it.
			$value = 'posts_per_page' === $field ? (int) $input[ $field ] : absint( $input[ $field ] );

			$request->set_param( $field, $value );
			$has_rest = true;
		}

		if ( $has_rest ) {
			$response = rest_do_request( $request );
			if ( $response->is_error() ) {
				return RestError::from( $response );
			}
		}

		// Non-REST keys: write via update_option() with per-type sanitization.
		if ( array_key_exists( 'posts_per_rss', $input ) ) {
			// Plain int (not absint) so core's sanitizer preserves the -1 sentinel.
			update_option( 'posts_per_rss', (int) $input['posts_per_rss'] );
		}

		if ( array_key_exists( 'blog_public', $input ) ) {
			update_option( 'blog_public', BooleanInput::sanitize( $input['blog_public'] ) ? 1 : 0 );
		}

		return array(
			'show_on_front'  => (string) ( get_option( 'show_on_front' ) ?? '' ),
			'page_on_front'  => absint( get_option( 'page_on_front' ) ),
			'page_for_posts' => absint( get_option( 'page_for_posts' ) ),
			// Plain (int), not absint: posts_per_page / posts_per_rss accept the -1
			// "show all" sentinel that the write path above preserves. absint() would
			// collapse a stored -1 to 1, misreporting the value just written.
			'posts_per_page' => (int) get_option( 'posts_per_page' ),
			'posts_per_rss'  => (int) get_option( 'posts_per_rss' ),
			'blog_public'    => (bool) get_option( 'blog_public' ),
		);
	}
}
