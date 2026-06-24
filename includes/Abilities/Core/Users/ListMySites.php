<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed T1 read ability: `users/list-my-sites`.
 *
 * Lists the sites (blogs) in a multisite network that the CURRENT user is a member
 * of, so a non-super-admin can discover the `blog_id`s a site-scoped ability's
 * multisite hint points at. Built on `get_blogs_of_user( get_current_user_id() )`
 * (wp-includes/user.php:1034) since core exposes no REST route for a user's site
 * membership; that function is in wp-includes, so no wp-admin includes are loaded.
 *
 * `get_blogs_of_user()` returns an OBJECT MAP keyed by blog id, each object carrying
 * `userblog_id`, `blogname`, `path`, `siteurl` (plus `domain`, `site_id`, `archived`,
 * `spam`, `deleted` this ability ignores). There is no `name`/`url`/`blog_id` key, so
 * this ability maps `userblog_id -> blog_id`, `blogname -> name`, `siteurl -> url`,
 * `path -> path`. An empty/no-membership result returns `array()` -> `sites: []`.
 *
 * Why this exists distinct from `network/list-sites`: that ability is `manage_sites`
 * (super-admin) gated, so a plain site administrator cannot use it to learn which
 * blog_ids they may act on. This read is the non-super-admin discovery surface.
 *
 * Scope `user`: a user's site membership is network-global identity, not per-site
 * state, so the multisite policy decorator must NOT inject `blog_id` or switch into a
 * blog around it.
 *
 * @since 0.4.0
 */
final class ListMySites implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/list-my-sites';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List My Sites', 'abilities-catalog' ),
			'description'         => __( 'Lists the sites (blogs) in a multisite network that the current user is a member of, returning each site\'s blog_id, name, url, and path. Use this to discover the blog_id values that a site-scoped ability accepts to target a specific site. Unlike network/list-sites (super-admin only), this works for any logged-in user and returns only the sites they belong to. Requires a multisite install: on a single-site install it returns an abilities_catalog_requires_multisite 400 error.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sites', 'total' ),
				'properties'           => array(
					'sites' => array(
						'type'        => 'array',
						'description' => __( 'The sites the current user is a member of, one row per site.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'blog_id', 'name', 'url', 'path' ),
							'properties'           => array(
								'blog_id' => array(
									'type'        => 'integer',
									'description' => __( 'The site (blog) ID. Pass this as blog_id to a site-scoped ability to target this site on multisite.', 'abilities-catalog' ),
								),
								'name'    => array(
									'type'        => 'string',
									'description' => __( 'The site name (the blogname / "Site Title" option for this blog).', 'abilities-catalog' ),
								),
								'url'     => array(
									'type'        => 'string',
									'description' => __( 'The site home URL (the siteurl option for this blog).', 'abilities-catalog' ),
								),
								'path'    => array(
									'type'        => 'string',
									'description' => __( 'The site path within the network (e.g. "/" for the root site or "/team/" for a sub-directory site).', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of sites returned (the length of the sites array).', 'abilities-catalog' ),
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
					'scope' => 'user',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the caller must be logged in.
	 *
	 * The hard guard is `is_user_logged_in()` ONLY — deliberately NOT
	 * `&& is_multisite()`. This diverges from `network/add-user-to-site`, whose
	 * `permission_callback` DOES gate on `is_multisite()`: that is acceptable there
	 * because the ability is super-admin only and a single-site denial is fine. Here
	 * the ability is a logged-in-user discovery tool, so the single-site case must NOT
	 * collapse to a generic `ability_invalid_permissions` — instead it must reach
	 * `execute()`, which returns the friendly `abilities_catalog_requires_multisite`
	 * 400 telling the agent exactly why the read is unavailable. Gating on
	 * `is_multisite()` here would hide that message behind a permission denial.
	 *
	 * A user's site membership is per-user identity, not per-site state, so no
	 * capability beyond being logged in is needed: every caller may read their own
	 * memberships, and `get_blogs_of_user()` is scoped to the current user.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input = null ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by listing the current user's site memberships.
	 *
	 * Mirrors `network/add-user-to-site`'s execute()-top multisite guard: the site
	 * membership tables are meaningless on a single-site install, so a 400 is returned
	 * before calling `get_blogs_of_user()`.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The sites list and total count, or a 400 on single-site.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$blogs = get_blogs_of_user( get_current_user_id() );

		$sites = array();

		foreach ( $blogs as $blog ) {
			$sites[] = array(
				'blog_id' => (int) ( $blog->userblog_id ?? 0 ),
				'name'    => (string) ( $blog->blogname ?? '' ),
				'url'     => (string) ( $blog->siteurl ?? '' ),
				'path'    => (string) ( $blog->path ?? '' ),
			);
		}

		return array(
			'sites' => $sites,
			'total' => count( $sites ),
		);
	}
}
