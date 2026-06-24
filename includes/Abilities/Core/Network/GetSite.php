<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core-function T1 read ability: `network/get-site`.
 *
 * Returns one site (blog) in a multisite network by its `blog_id`, projected
 * into a flat closed row: the same twelve fields `network/list-sites` emits,
 * plus the site's display name (`blogname`) and WordPress address (`siteurl`)
 * read from that site's own options. The single-object companion to
 * `network/list-sites`. Built on `get_site()` (wp-includes/ms-site.php:310 ->
 * `WP_Site|null`) since core exposes no REST route for sites; no wp-admin
 * includes are loaded.
 *
 * `WP_Site` public properties are numeric strings (wp-includes/class-wp-site.php),
 * so each field is cast deliberately: `(int)` for ids, and `(bool) (int)` for the
 * five status flags — a bare `(bool)` on the string `'0'` would be truthy, so the
 * `(int)` cast must come first. The `url` field is built cheaply from
 * `domain . path` (no scheme); `$site->home`/`$site->siteurl` are NOT plain props
 * (the `__get` getter lazy-loads them via `switch_to_blog()`), so `url` avoids
 * that per-row blog switch. The display name and address are read with
 * `get_blog_option()` (wp-includes/ms-blogs.php:357), which switches to the blog
 * and restores internally, so it is safe to call here.
 *
 * Multisite only: these tables (`wp_blogs`) do not exist on a single site, so
 * `execute()` returns a 400 before touching any `ms-*` function, mirroring the
 * "explicit guard at the top of execute() when the wrapped core fn has no route
 * to surface an error" idiom (`tools/delete-transient`).
 *
 * @since 0.1.0
 */
final class GetSite implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/get-site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Site', 'abilities-catalog' ),
			'description'         => __( 'Returns one site (blog) in a multisite network by its blog_id, including its domain, path, url, status flags, display name (blogname), and WordPress address (siteurl). Single-site read; enumerate sites with network/list-sites. An unknown blog_id returns a 404 rest_site_invalid_id error. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id' ),
				'properties'           => array(
					'blog_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The site (blog) ID to fetch. Discover IDs with network/list-sites.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id', 'network_id', 'domain', 'path', 'url', 'registered', 'last_updated', 'public', 'archived', 'mature', 'spam', 'deleted', 'blogname', 'siteurl' ),
				'properties'           => array(
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'The site (blog) ID; pass to network/get-site.', 'abilities-catalog' ),
					),
					'network_id'   => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the parent network this site belongs to.', 'abilities-catalog' ),
					),
					'domain'       => array(
						'type'        => 'string',
						'description' => __( 'The site domain.', 'abilities-catalog' ),
					),
					'path'         => array(
						'type'        => 'string',
						'description' => __( 'The site path.', 'abilities-catalog' ),
					),
					'url'          => array(
						'type'        => 'string',
						'description' => __( 'A cheap URL built from the site domain and path (no scheme). It is NOT fetched via the site home option, so it has no http(s):// prefix; for the canonical address use siteurl.', 'abilities-catalog' ),
					),
					'registered'   => array(
						'type'        => 'string',
						'description' => __( 'When the site was registered, as a MySQL datetime in UTC (0000-00-00 00:00:00 when unset).', 'abilities-catalog' ),
					),
					'last_updated' => array(
						'type'        => 'string',
						'description' => __( 'When the site was last updated, as a MySQL datetime in UTC (0000-00-00 00:00:00 when unset).', 'abilities-catalog' ),
					),
					'public'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is public (visible to search engines and listings).', 'abilities-catalog' ),
					),
					'archived'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is archived.', 'abilities-catalog' ),
					),
					'mature'       => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is flagged mature.', 'abilities-catalog' ),
					),
					'spam'         => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is flagged as spam.', 'abilities-catalog' ),
					),
					'deleted'      => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is flagged for deletion.', 'abilities-catalog' ),
					),
					'blogname'     => array(
						'type'        => 'string',
						'description' => __( 'The site display name (Settings -> General title), read from that site own options.', 'abilities-catalog' ),
					),
					'siteurl'      => array(
						'type'        => 'string',
						'description' => __( 'The site WordPress address (siteurl option), the canonical URL including scheme.', 'abilities-catalog' ),
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
					'scope' => 'network',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: multisite, and the current user may manage sites.
	 *
	 * The hard guard is `is_multisite() && current_user_can( 'manage_sites' )`.
	 * `manage_sites` is a network (super-admin) capability; a plain site
	 * administrator does not hold it, and on a single site it is granted to no
	 * one — correct, since the ability is meaningless there. The guard is
	 * object-independent: an unknown `blog_id` surfaces as the specific 404 from
	 * execute(), never as a permission denial. `blog_id` is not a secret, so it
	 * may appear in that error message.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by reading one site and projecting it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The site row, a 400 on single-site, or a 404 if no site matches.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$input   = is_array( $input ) ? $input : array();
		$blog_id = absint( $input['blog_id'] ?? 0 );

		$site = get_site( $blog_id );

		if ( ! $site instanceof WP_Site ) {
			return new WP_Error(
				'rest_site_invalid_id',
				__( 'Invalid site ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$id = (int) $site->blog_id;

		return array(
			'blog_id'      => $id,
			'network_id'   => (int) $site->site_id,
			'domain'       => (string) $site->domain,
			'path'         => (string) $site->path,
			'url'          => untrailingslashit( $site->domain . $site->path ),
			'registered'   => (string) $site->registered,
			'last_updated' => (string) $site->last_updated,
			'public'       => (bool) (int) $site->public,
			'archived'     => (bool) (int) $site->archived,
			'mature'       => (bool) (int) $site->mature,
			'spam'         => (bool) (int) $site->spam,
			'deleted'      => (bool) (int) $site->deleted,
			'blogname'     => (string) get_blog_option( $id, 'blogname' ),
			'siteurl'      => (string) get_blog_option( $id, 'siteurl' ),
		);
	}
}
