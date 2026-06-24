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
 * Dangerous-tier destructive write ability: `network/delete-site`.
 *
 * Permanently deletes one site (blog) from a multisite network by its `blog_id`,
 * wrapping `wp_delete_site()` (wp-includes/ms-site.php:212 -> the deleted
 * `WP_Site` on success, or a `WP_Error`). This drops the site row and all of its
 * database tables and content; it cannot be undone. There is no REST route for
 * sites, so this uses the core-function idiom; no wp-admin includes are loaded
 * (`wp_delete_site()` lives in wp-includes/).
 *
 * Main-site guard (this ability's responsibility, not core's): `wp_delete_site()`
 * fires the `wp_validate_site_deletion` action (ms-site.php:237) but core
 * registers NO callback on it, so core does NOT block deleting the network's main
 * site — that guard lives only in wp-admin/network/sites.php (the
 * `! is_main_site( $id )` check). Calling `wp_delete_site( 1 )` directly would
 * drop the main site, so this ability adds its own `is_main_site()` -> 409 guard
 * BEFORE the delete (wp-includes/functions.php:6449). A plugin that DOES hook
 * `wp_validate_site_deletion` returns its `WP_Error` from `wp_delete_site()`; that
 * is surfaced verbatim (mapped to 409), not bypassed.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write.
 * - `destructive` is true: it is irreversible (the site and its tables are
 *   permanently dropped). For a reversible offline toggle use
 *   `network/update-site` (archived/spam/deleted).
 * - `idempotent` is false: a second call for the same `blog_id` returns a 404
 *   (`site_not_exist`), not a same-state no-op.
 * - `dangerous` is true: it permanently destroys a tenant of the network. There is
 *   no `Support/` guard (no filesystem/source/upgrader/option-allow-list risk class
 *   applies); the hard guard is the `manage_sites` (super-admin) capability plus the
 *   multisite and main-site pre-checks in {@see self::execute()}. The Registry
 *   auto-lists any `dangerous` ability in the `abilities_catalog_dangerous_tools`
 *   filter.
 *
 * Multisite only: the `wp_blogs` table does not exist on a single site, so
 * `execute()` returns a 400 before touching any `ms-*` function.
 *
 * Security note: `wp_delete_site()` performs NO capability check of its own. The
 * `permission_callback` plus the explicit `current_user_can( 'manage_sites' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 *
 * @since 0.1.0
 */
final class DeleteSite implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/delete-site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Delete Site', 'abilities-catalog' ),
			'description'         => __( 'Permanently deletes a site (blog) from a multisite network by blog_id, dropping the site and ALL of its database tables and content. This cannot be undone. The network\'s main site cannot be deleted (returns a 409). To take a site offline reversibly instead, use network/update-site with archived/spam/deleted. An unknown blog_id returns a 404. This is a dangerous, irreversible network operation. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id' ),
				'properties'           => array(
					'blog_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The site (blog) ID to permanently delete. Discover IDs with network/list-sites. The network\'s main site cannot be deleted.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'deleted', 'blog_id' ),
				'properties'           => array(
					'deleted' => array(
						'type'        => 'boolean',
						'description' => __( 'True once the site and all its tables were permanently removed.', 'abilities-catalog' ),
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'The blog ID of the deleted site, echoed back. No edit/view link is returned because the site no longer exists.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'abilities_catalog' => array(
					'scope' => 'network',
				),
				'show_in_rest'      => true,
				'screen'            => 'sites.php',
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
	 * object-independent: an unknown or main-site `blog_id` surfaces as the
	 * specific 404/409 from execute(), never as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by permanently deleting one site.
	 *
	 * The explicit `current_user_can( 'manage_sites' )` check is repeated here, at
	 * the top and before any state branch, because `wp_delete_site()` performs no
	 * capability check of its own and there is no wrapped route to surface a denial.
	 * The main-site guard runs before the delete because core does not block it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The deleted flag and blog id, or a WP_Error.
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
				__( 'You are not allowed to delete sites.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input   = is_array( $input ) ? $input : array();
		$blog_id = absint( $input['blog_id'] ?? 0 );

		// Main-site guard: core does NOT block deleting the main site, so this is
		// the ability's responsibility. Refuse it with a 409 before the delete.
		if ( is_main_site( $blog_id ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_delete_main_site',
				__( 'The network\'s main site cannot be deleted.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		$result = wp_delete_site( $blog_id );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 'site_not_exist' === $result->get_error_code() ? 404 : 409 )
			);
		}

		return array(
			'deleted' => true,
			'blog_id' => (int) ( $result instanceof WP_Site ? $result->blog_id : $blog_id ),
		);
	}
}
