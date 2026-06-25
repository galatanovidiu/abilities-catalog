<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\BooleanInput;
use WP_Error;
use WP_Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-network/list-sites`.
 *
 * Lists the sites (blogs) in a multisite network, one flat row per site, with
 * optional status filters and paging, so an agent can enumerate the network's
 * sites and recover each site's `blog_id` for the other network site abilities.
 *
 * Wraps `get_sites()` (wp-includes/ms-site.php:445 -> `WP_Site[]`). Core exposes
 * no REST route for the multisite site list, so this uses the core-function
 * idiom. `total` comes from a SEPARATE `get_sites()` count query
 * (`array( 'count' => true )` returns `(int)`, wp-includes/class-wp-site-query.php:386),
 * never `count()` of the returned page — a `number`-capped page is smaller than
 * the matching total.
 *
 * Multisite-only: on a single site the `wp_blogs` table the function reads does
 * not exist and `manage_sites` is granted to no one. The `permission_callback`
 * returns false off multisite (so RegistryTest still registers the ability while
 * no one can run it), and `execute()` opens with an explicit `is_multisite()`
 * guard before touching any `ms-*` function — mirroring the
 * "explicit guard at the top of execute() when the wrapped core fn has no route
 * to surface an error" idiom (`og-tools/delete-transient`).
 *
 * Projection notes (kept in sync with the inline row schema):
 * - `WP_Site` public props are numeric STRINGS (wp-includes/class-wp-site.php).
 *   Integer fields cast with `(int)`; the magic getter maps `id -> (int) blog_id`
 *   and `network_id -> (int) site_id` (:223), but the raw props are read here.
 * - The five status flags cast with `(bool) (int) $site->{prop}`: the prop is the
 *   string `'0'`/`'1'`, so a bare `(bool)` would treat `'0'` as truthy; the `(int)`
 *   runs first.
 * - `url` is built cheaply from `domain . path`. It deliberately does NOT read
 *   `$site->home`/`$site->siteurl`, because `__get` lazy-loads those via
 *   `get_details()` (wp-includes/class-wp-site.php:319) which calls
 *   `switch_to_blog()` once per row — expensive for a list. So `url` carries no
 *   scheme; `og-network/get-site` returns the full `siteurl` for a single site.
 *
 * @since 0.1.0
 */
final class ListSites implements Ability {

	/**
	 * Optional boolean status filters passed through to `WP_Site_Query`.
	 *
	 * Added to the query args only when the caller supplied the key, so omitting a
	 * filter returns sites in both states (the `WP_Site_Query` default).
	 *
	 * @var array<int,string>
	 */
	private const STATUS_FILTERS = array( 'public', 'archived', 'spam', 'deleted', 'mature' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-network/list-sites';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Sites', 'abilities-catalog' ),
			'description'         => __( 'Lists the sites (blogs) in a WordPress multisite network, one row per site with its blog_id, domain, path, url, timestamps, and status flags (public/archived/mature/spam/deleted). Use og-network/get-site for one site\'s full detail including its name. An empty result means no sites match the filters. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'og-core-network',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'number'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 200,
						'default'     => 100,
						'description' => __( 'How many sites to return (page size).', 'abilities-catalog' ),
					),
					'offset'     => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'How many matching sites to skip before returning results (for paging).', 'abilities-catalog' ),
					),
					'search'     => array(
						'type'        => 'string',
						'description' => __( 'Optional substring to match against site domain/path.', 'abilities-catalog' ),
					),
					'network_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( "Restrict to one network's sites (multi-network installs). Discover network IDs with og-network/list-networks. Omit for the current network.", 'abilities-catalog' ),
					),
					'public'     => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to public (true) or non-public (false) sites; omit for both.', 'abilities-catalog' ),
					),
					'archived'   => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to archived (true) or non-archived (false) sites; omit for both.', 'abilities-catalog' ),
					),
					'spam'       => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to sites flagged as spam (true) or not (false); omit for both.', 'abilities-catalog' ),
					),
					'deleted'    => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to sites flagged for deletion (true) or not (false); omit for both.', 'abilities-catalog' ),
					),
					'mature'     => array(
						'type'        => 'boolean',
						'description' => __( 'Filter to sites flagged mature (true) or not (false); omit for both.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sites', 'total' ),
				'properties'           => array(
					'sites' => array(
						'type'        => 'array',
						'description' => __( 'The list of sites as flat rows. Use og-network/get-site for one site\'s full detail.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'blog_id', 'network_id', 'domain', 'path', 'url', 'registered', 'last_updated', 'public', 'archived', 'mature', 'spam', 'deleted' ),
							'properties'           => array(
								'blog_id'      => array(
									'type'        => 'integer',
									'description' => __( 'The site (blog) ID; pass to og-network/get-site.', 'abilities-catalog' ),
								),
								'network_id'   => array(
									'type'        => 'integer',
									'description' => __( 'The ID of the parent network this site belongs to.', 'abilities-catalog' ),
								),
								'domain'       => array(
									'type'        => 'string',
									'description' => __( "The site's domain.", 'abilities-catalog' ),
								),
								'path'         => array(
									'type'        => 'string',
									'description' => __( "The site's path within the domain.", 'abilities-catalog' ),
								),
								'url'          => array(
									'type'        => 'string',
									'description' => __( "A cheap URL built from the site's domain and path (no scheme). It is NOT fetched via the site's home option, so it has no http(s):// prefix; use og-network/get-site for the canonical siteurl.", 'abilities-catalog' ),
								),
								'registered'   => array(
									'type'        => 'string',
									'description' => __( 'When the site was registered, as a MySQL datetime in UTC ("0000-00-00 00:00:00" when unset).', 'abilities-catalog' ),
								),
								'last_updated' => array(
									'type'        => 'string',
									'description' => __( 'When the site was last updated, as a MySQL datetime in UTC ("0000-00-00 00:00:00" when unset).', 'abilities-catalog' ),
								),
								'public'       => array(
									'type'        => 'boolean',
									'description' => __( 'Whether the site is public.', 'abilities-catalog' ),
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
							),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'Total number of sites matching the filters across all pages.', 'abilities-catalog' ),
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
	 * Permission check: multisite + the network super-admin `manage_sites` cap.
	 *
	 * `manage_sites` is a network (super-admin) capability, not the per-site
	 * `manage_options`: enumerating every site in the network is network-admin
	 * work, so a plain site administrator must not run it. On a single site
	 * `is_multisite()` is false, so this denies everyone (the ability is
	 * meaningless there); RegistryTest still registers it.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the install is multisite and the user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by querying the network's sites.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The sites list and total, or an error.
	 */
	public function execute( $input ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$input = is_array( $input ) ? $input : array();

		$number = isset( $input['number'] ) ? (int) $input['number'] : 100;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;

		$args = array(
			'number'  => $number,
			'offset'  => $offset,
			'orderby' => 'id',
			'order'   => 'ASC',
		);

		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}

		if ( isset( $input['network_id'] ) ) {
			$args['network_id'] = absint( $input['network_id'] );
		}

		foreach ( self::STATUS_FILTERS as $filter ) {
			if ( ! array_key_exists( $filter, $input ) ) {
				continue;
			}

			$args[ $filter ] = BooleanInput::sanitize( $input[ $filter ] ) ? 1 : 0;
		}

		$sites = get_sites( $args );

		$rows = array();

		foreach ( $sites as $site ) {
			if ( ! $site instanceof WP_Site ) {
				continue;
			}

			$rows[] = array(
				'blog_id'      => (int) $site->blog_id,
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
			);
		}

		$total = (int) get_sites(
			array_merge(
				$args,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		return array(
			'sites' => $rows,
			'total' => $total,
		);
	}
}
