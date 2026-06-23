<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_Network;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `network/create-site`.
 *
 * Creates a new site (blog) in a multisite network under a slug, with a title
 * and an existing user as its administrator — the Network Admin -> Sites -> Add
 * New action. Wraps core `wpmu_create_blog()` (wp-includes/ms-functions.php:1404
 * -> the new `blog_id` int on success, or `WP_Error` `blog_taken` when the
 * domain/path already exists). `wpmu_create_blog()` is a `wp-includes` function,
 * not a `wp-admin/includes` one (site-new.php only wraps it), so no admin
 * includes are loaded. The domain/path are derived from whether the network is
 * subdomain- or subdirectory-based, mirroring wp-admin/network/site-new.php:103-109
 * exactly, and the call mirrors site-new.php:144
 * (`wpmu_create_blog( $newdomain, $path, $title, $user_id, $meta, get_current_network_id() )`).
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it provisions new `wp_blogs` rows and a
 *   full set of per-site tables).
 * - `destructive` is false: a created site is reversible — remove it with
 *   `network/delete-site`.
 * - `idempotent` is false: a second identical call collides on the domain/path
 *   (`blog_taken`), so repeating it is not a same-state no-op.
 * - `dangerous` is true: provisioning a new set of database tables is a broad
 *   network operation. There is no `Support/` guard (no filesystem/source/
 *   upgrader/option-allow-list risk class applies); the hard guard is
 *   `create_sites` (a super-admin capability) plus the existence pre-check in
 *   {@see self::execute()}. The Registry auto-lists any `dangerous` ability in the
 *   `abilities_catalog_dangerous_tools` filter.
 *
 * Multisite only: the `wp_blogs` table does not exist on a single site, so
 * `execute()` returns a 400 before touching any `ms-*` function, mirroring the
 * "explicit guard at the top of execute() when the wrapped core fn has no route
 * to surface an error" idiom (`tools/delete-transient`).
 *
 * Security note: core's `wpmu_create_blog()` performs NO capability check of its
 * own. The `permission_callback` plus the explicit `current_user_can( 'create_sites' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 *
 * @since 0.1.0
 */
final class CreateSite implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/create-site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Create Site', 'abilities-catalog' ),
			'description'         => __( 'Creates a new site (blog) in a WordPress multisite network under a slug, with a title and an existing user as its administrator — the Network Admin Add New Site action. Derives the domain/path from whether the network is subdomain- or subdirectory-based. admin_id must be an existing user (discover with users/list-users); the site is created with public visibility. Creating a site provisions a new set of database tables, so this is a dangerous network operation; remove a site with network/delete-site. Fails with a 409 if the slug is already taken. Requires a multisite install and the create_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'slug', 'title', 'admin_id' ),
				'properties'           => array(
					'slug'     => array(
						'type'        => 'string',
						'minLength'   => 1,
						'pattern'     => '^[a-zA-Z0-9-]+$',
						'description' => __( "The new site's address segment: the subdirectory name (subdirectory installs, becomes /slug/) or the subdomain label (subdomain installs, becomes slug.example.com). Lowercased and validated like the Add New Site screen; letters, digits, and hyphens only.", 'abilities-catalog' ),
					),
					'title'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( "The new site's title (Settings -> General title).", 'abilities-catalog' ),
					),
					'admin_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( "The ID of an EXISTING user who becomes the new site's administrator. Discover IDs with users/list-users. The user is not created; an unknown id is rejected with a 404.", 'abilities-catalog' ),
					),
					'lang'     => array(
						'type'        => 'string',
						'description' => __( "Optional. A locale to set for the new site (e.g. 'de_DE'); must already be installed (in get_available_languages). Ignored if not installed. Omit for the site default.", 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id', 'url' ),
				'properties'           => array(
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( "The new site's blog ID. Pass it to network/get-site, network/update-site, or network/add-user-to-site.", 'abilities-catalog' ),
					),
					'url'     => array(
						'type'        => 'string',
						'description' => __( "The new site's home URL (from get_home_url), including scheme.", 'abilities-catalog' ),
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
					'dangerous'   => true,
				),
				'show_in_rest' => true,
				'screen'       => 'site-new.php',
			),
		);
	}

	/**
	 * Permission check: multisite, and the current user may create sites.
	 *
	 * The hard guard is `is_multisite() && current_user_can( 'create_sites' )`.
	 * `create_sites` is a network (super-admin) capability; a plain site
	 * administrator does not hold it, and on a single site it is granted to no one
	 * — correct, since the ability is meaningless there. The guard is
	 * object-independent: an unknown `admin_id` surfaces as the specific 404 from
	 * execute(), never as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can create sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'create_sites' );
	}

	/**
	 * Executes the ability by creating the new site.
	 *
	 * The explicit `current_user_can( 'create_sites' )` check is repeated here, at
	 * the top and before any state branch, because the wrapped core function
	 * (`wpmu_create_blog()`) performs no capability check of its own and there is
	 * no REST route to surface a denial. This branch is normally unreachable (the
	 * `permission_callback` blocks first) but keeps `execute()` safe if ever called
	 * directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The new site, a 400 on single-site, a
	 *                                        403 on a denied caller, a 404 for an
	 *                                        unknown admin, or a 409 if the slug is
	 *                                        taken.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'create_sites' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_create_sites',
				__( 'You are not allowed to create sites in this network.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input    = is_array( $input ) ? $input : array();
		$slug     = strtolower( (string) ( $input['slug'] ?? '' ) );
		$title    = (string) ( $input['title'] ?? '' );
		$admin_id = absint( $input['admin_id'] ?? 0 );

		if ( ! get_userdata( $admin_id ) ) {
			return new WP_Error(
				'rest_user_invalid_id',
				__( 'Invalid user ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$network = get_network();
		if ( ! $network instanceof WP_Network ) {
			return new WP_Error(
				'abilities_catalog_create_site_failed',
				__( 'The site could not be created.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		// Domain/path derivation, mirroring wp-admin/network/site-new.php:103-109.
		if ( is_subdomain_install() ) {
			$newdomain = $slug . '.' . preg_replace( '|^www\.|', '', $network->domain );
			$path      = $network->path;
		} else {
			$newdomain = $network->domain;
			$path      = $network->path . $slug . '/';
		}

		$meta = array( 'public' => 1 );

		$lang = isset( $input['lang'] ) ? (string) $input['lang'] : '';
		if ( '' !== $lang && in_array( $lang, get_available_languages(), true ) ) {
			$meta['WPLANG'] = $lang;
		}

		$blog_id = wpmu_create_blog( $newdomain, $path, $title, $admin_id, $meta, get_current_network_id() );

		if ( is_wp_error( $blog_id ) ) {
			return new WP_Error(
				$blog_id->get_error_code(),
				$blog_id->get_error_message(),
				array( 'status' => 'blog_taken' === $blog_id->get_error_code() ? 409 : 500 )
			);
		}

		if ( ! is_int( $blog_id ) || $blog_id <= 0 ) {
			return new WP_Error(
				'abilities_catalog_create_site_failed',
				__( 'The site could not be created.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'blog_id' => $blog_id,
			'url'     => (string) get_home_url( $blog_id ),
		);
	}
}
