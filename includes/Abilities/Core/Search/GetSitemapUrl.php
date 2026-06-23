<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Search;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read ability: `search/get-sitemap-url`.
 *
 * Returns the site's XML sitemap index URL and whether sitemaps are enabled,
 * read directly from core. Net-new read: no REST route is dispatched.
 *
 * Wraps `get_sitemap_url( 'index' )` for the index URL (`/wp-sitemap.xml` with
 * pretty permalinks, `/?sitemap=index` without) and
 * `wp_sitemaps_get_server()->sitemaps_enabled()` for the enabled flag, which is
 * `(bool) get_option( 'blog_public' )` filtered by `wp_sitemaps_enabled`. When
 * sitemaps are disabled the index URL still exists but core returns a 404 to
 * crawlers, so `enabled` is the field that says whether the URL is live.
 *
 * @since 0.6.0
 */
final class GetSitemapUrl implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'search/get-sitemap-url';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Sitemap URL', 'abilities-catalog' ),
			'description'         => __( 'Returns the site\'s XML sitemap index URL and whether sitemaps are enabled. When enabled is false the site discourages search engines (the Reading setting blog_public is off), so this URL returns a 404 to crawlers even though the address still resolves. Use enabled, not the URL, to tell whether the sitemap is live before handing it to a search engine.', 'abilities-catalog' ),
			'category'            => 'search',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sitemap_url', 'enabled' ),
				'properties'           => array(
					'sitemap_url' => array(
						'type'        => 'string',
						'description' => __( 'The full URL of the XML sitemap index (e.g. https://example.com/wp-sitemap.xml with pretty permalinks, or .../?sitemap=index without).', 'abilities-catalog' ),
					),
					'enabled'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether sitemaps are enabled. False when the Reading setting blog_public is off; the URL then returns a 404 to crawlers.', 'abilities-catalog' ),
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
	 * Permission check: `edit_posts` (catalog capability for sitemap reads).
	 *
	 * XML sitemap config is public, non-sensitive SEO data; this catalog ability
	 * gates it on `edit_posts` so the read stays an authenticated tool, consistent
	 * with the other `search` abilities. The hard server-side guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may read the sitemap URL.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Executes the ability by reading the sitemap index URL and enabled flag.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The sitemap index URL and enabled flag.
	 */
	public function execute( $input = null ) {
		$server = wp_sitemaps_get_server();

		return array(
			'sitemap_url' => (string) get_sitemap_url( 'index' ),
			'enabled'     => $server ? (bool) $server->sitemaps_enabled() : false,
		);
	}
}
