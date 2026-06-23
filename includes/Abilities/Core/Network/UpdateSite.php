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
 * Dangerous-tier core-function write ability: `network/update-site`.
 *
 * Updates one site (blog) in a multisite network by its `blog_id` — its status
 * flags (`public`/`archived`/`mature`/`spam`/`deleted`) and/or its `domain`/`path`.
 * Wraps core `wp_update_site()` (wp-includes/ms-site.php:159). Only the keys the
 * caller actually provides are forwarded into the `$data` array, so an absent flag
 * never flips and an explicit `false` reaches core; if no field is provided the
 * call is rejected with a 400 before touching core.
 *
 * Core return shape: `wp_update_site()` returns the **new site id as an `(int)`**
 * (wp-includes/ms-site.php:199), NOT a `WP_Site`, so this ability re-reads the
 * updated site with `get_site()` afterwards to project the resulting fields. On
 * failure it returns a `WP_Error` (`site_empty_id`, `site_not_exist`,
 * `db_update_error`, or a `wp_prepare_site_data` validation error).
 *
 * `WP_Site` public status properties are numeric strings (`'1'`/`'0'`,
 * wp-includes/class-wp-site.php), so each flag is cast `(bool) (int)` — a bare
 * `(bool) '0'` is truthy, so the `(int)` cast must come first. The boolean INPUT
 * flags are coerced with `BooleanInput::sanitize()` (NOT `rest_sanitize_boolean()`
 * on a `mixed` value, which fails phpstan `argument.templateType`) and stored as
 * core's `1`/`0`.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write.
 * - `destructive` is false: toggling status flags is reversible (unset the flag)
 *   and this does NOT drop the site's tables — `network/delete-site` does that.
 * - `idempotent` is true: re-applying the same flags yields the same state.
 * - `dangerous` is true: setting `archived`/`spam`/`deleted` takes the site OFFLINE
 *   for its visitors (a notice replaces the front end) — a wide user-visible blast
 *   radius. There is no `Support/` guard (no filesystem/source/upgrader/option
 *   risk class applies); the hard guard is the network `manage_sites` capability
 *   plus the explicit cap repeat at the top of {@see self::execute()}. The Registry
 *   auto-lists any `dangerous` ability in the `abilities_catalog_dangerous_tools`
 *   filter.
 *
 * Multisite only: the `wp_blogs` table does not exist on a single site, so
 * `execute()` returns a 400 before touching any `ms-*` function.
 *
 * Security note: core's `wp_update_site()` performs NO capability check of its own.
 * The `permission_callback` plus the explicit `current_user_can( 'manage_sites' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 *
 * @since 0.1.0
 */
final class UpdateSite implements Ability {

	/**
	 * The optional boolean status flags that can be toggled.
	 *
	 * @var string[]
	 */
	private const STATUS_FLAGS = array( 'public', 'archived', 'mature', 'spam', 'deleted' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/update-site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Update Site', 'abilities-catalog' ),
			'description'         => __( 'Updates one site (blog) in a multisite network by blog_id: its status flags (public/archived/mature/spam/deleted) and/or its domain/path. Only the fields you provide are changed; supply at least one or the call returns a 400. Setting archived, spam, or deleted takes the site OFFLINE for its visitors (a notice replaces the front end), so this is a dangerous network operation; it is reversible by unsetting the flag. This is a status/soft-delete toggle — it does NOT drop the site\'s tables; use network/delete-site to permanently remove a site. An unknown blog_id returns a 404. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id' ),
				'properties'           => array(
					'blog_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The site (blog) ID to update. Discover IDs with network/list-sites.', 'abilities-catalog' ),
					),
					'public'   => array(
						'type'        => 'boolean',
						'description' => __( 'Optional. Site visibility flag (whether the site is visible to search engines and listings).', 'abilities-catalog' ),
					),
					'archived' => array(
						'type'        => 'boolean',
						'description' => __( 'Optional. Set true to archive the site (taken offline; the front end shows an archived notice), false to un-archive.', 'abilities-catalog' ),
					),
					'mature'   => array(
						'type'        => 'boolean',
						'description' => __( 'Optional. Mature-content flag.', 'abilities-catalog' ),
					),
					'spam'     => array(
						'type'        => 'boolean',
						'description' => __( 'Optional. Set true to flag the site as spam (taken offline), false to clear the flag.', 'abilities-catalog' ),
					),
					'deleted'  => array(
						'type'        => 'boolean',
						'description' => __( 'Optional. Set true to mark the site deleted (soft-deleted/offline; this does NOT drop its tables — use network/delete-site for that), false to undelete.', 'abilities-catalog' ),
					),
					'domain'   => array(
						'type'        => 'string',
						'description' => __( 'Optional. The site\'s domain. Changing it can break access; usually leave unset.', 'abilities-catalog' ),
					),
					'path'     => array(
						'type'        => 'string',
						'description' => __( 'Optional. The site\'s path (e.g. /blog/). Changing it can break access; usually leave unset.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id', 'domain', 'path', 'public', 'archived', 'mature', 'spam', 'deleted' ),
				'properties'           => array(
					'blog_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The site (blog) ID that was updated.', 'abilities-catalog' ),
					),
					'domain'   => array(
						'type'        => 'string',
						'description' => __( 'The site domain after the update.', 'abilities-catalog' ),
					),
					'path'     => array(
						'type'        => 'string',
						'description' => __( 'The site path after the update.', 'abilities-catalog' ),
					),
					'public'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is public (visible to search engines and listings), after the update.', 'abilities-catalog' ),
					),
					'archived' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is archived (offline), after the update.', 'abilities-catalog' ),
					),
					'mature'   => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is flagged mature, after the update.', 'abilities-catalog' ),
					),
					'spam'     => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is flagged as spam (offline), after the update.', 'abilities-catalog' ),
					),
					'deleted'  => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the site is flagged deleted (offline; tables not dropped), after the update.', 'abilities-catalog' ),
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
					'idempotent'  => true,
					'dangerous'   => true,
				),
				'show_in_rest' => true,
				'screen'       => 'sites.php',
			),
		);
	}

	/**
	 * Permission check: multisite, and the current user may manage sites.
	 *
	 * The hard guard is `is_multisite() && current_user_can( 'manage_sites' )`.
	 * `manage_sites` is a network (super-admin) capability; a plain site
	 * administrator does not hold it, and on a single site it is granted to no one —
	 * correct, since the ability is meaningless there. The guard is
	 * object-independent: an unknown `blog_id` surfaces as the specific 404 from
	 * execute(), never as a permission denial. `blog_id` is not a secret, so it may
	 * appear in that error message.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by updating one site and projecting the result.
	 *
	 * The explicit `current_user_can( 'manage_sites' )` check is repeated here, at
	 * the top and before any mutation or no-op branch, because `wp_update_site()`
	 * performs no capability check of its own. Only caller-provided keys are
	 * forwarded into `$data`; an empty `$data` is rejected with a 400 before the
	 * write. `wp_update_site()` returns the new site id as an `(int)`, so the site
	 * is re-read with `get_site()` to project the resulting fields.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The updated site row, or a WP_Error.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'manage_sites' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_sites',
				__( 'You are not allowed to manage network sites.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input   = is_array( $input ) ? $input : array();
		$blog_id = absint( $input['blog_id'] ?? 0 );

		// Forward only the keys the caller provided: an absent flag must not flip,
		// and an explicit false must reach core. Status flags store core's `1`/`0`.
		$data = array();
		foreach ( self::STATUS_FLAGS as $flag ) {
			if ( ! array_key_exists( $flag, $input ) ) {
				continue;
			}

			$data[ $flag ] = BooleanInput::sanitize( $input[ $flag ] ) ? 1 : 0;
		}
		foreach ( array( 'domain', 'path' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$data[ $field ] = (string) $input[ $field ];
		}

		if ( empty( $data ) ) {
			return new WP_Error(
				'abilities_catalog_no_changes',
				__( 'No site fields were provided to update. Supply at least one of: public, archived, mature, spam, deleted, domain, path.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$result = wp_update_site( $blog_id, $data );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => ( 'site_not_exist' === $result->get_error_code() ? 404 : 400 ) )
			);
		}

		$site = get_site( $blog_id );
		if ( ! $site instanceof WP_Site ) {
			return new WP_Error(
				'abilities_catalog_update_site_failed',
				__( 'The site was updated but could not be read back.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'blog_id'  => (int) $site->blog_id,
			'domain'   => (string) $site->domain,
			'path'     => (string) $site->path,
			'public'   => (bool) (int) $site->public,
			'archived' => (bool) (int) $site->archived,
			'mature'   => (bool) (int) $site->mature,
			'spam'     => (bool) (int) $site->spam,
			'deleted'  => (bool) (int) $site->deleted,
		);
	}
}
