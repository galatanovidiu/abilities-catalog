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
 * Ordinary core-function write ability: `network/remove-user-from-site`.
 *
 * Removes a user's membership from one site (blog) in a multisite network,
 * mirroring the Network Admin -> Sites -> Users action. Wraps core
 * `remove_user_from_blog( $user_id, $blog_id, $reassign )`
 * (wp-includes/ms-functions.php:239 -> `true|WP_Error`). Note the parameter
 * order is `( $user_id, $blog_id, $reassign )` — user first. Core strips the
 * user's capabilities on that site (`$user->remove_all_caps()`); the user
 * ACCOUNT itself, and their membership on other sites, are unaffected. The
 * optional `reassign` parameter is a user ID whose value reassigns the removed
 * user's posts on that site (0 = leave authorship unchanged).
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it strips membership/roles on a site).
 * - `destructive` is false: only the per-site membership is removed; the user
 *   account survives, removing absent membership is a no-op, and the user can be
 *   added back with `network/add-user-to-site`. There is no irreversible data
 *   loss (declared because every write must set the boolean).
 * - `idempotent` is true: removing a user who already has no membership on the
 *   site leaves the same end state, so a repeat call is a no-op success.
 * - NOT `dangerous`: this is per-site membership management, not a network-wide
 *   blast radius, so it gates in the `permission_callback` only — no execute()-top
 *   capability repeat (contrast the dangerous network writes).
 *
 * Multisite only: the `wp_blogs` / `wp_usermeta` tables and the network caps do
 * not exist on a single site, so `execute()` returns a 400 before touching any
 * `ms-*` function (the `network/get-site` / `tools/delete-transient` idiom).
 *
 * Security note: `remove_user_from_blog()` performs NO capability check of its
 * own. The `permission_callback` (`is_multisite() && manage_sites`) is the hard
 * authorization guard. `manage_sites` is a network (super-admin) capability; a
 * plain site administrator does not hold it. The honest 404 pre-checks (site,
 * user) run before the mutation so a missing object is not masked as a denial.
 *
 * @since 0.1.0
 */
final class RemoveUserFromSite implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/remove-user-from-site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Remove User From Site', 'abilities-catalog' ),
			'description'         => __( 'Removes a user\'s membership from a site (blog) in a multisite network. This only removes the user\'s roles/capabilities on that site — the user ACCOUNT is not deleted and their membership on other sites is unaffected. Optionally pass reassign (a user ID) to reassign the removed user\'s posts on that site; omit it (default 0) to leave authorship unchanged. An unknown user or site returns a 404. Add a user with network/add-user-to-site. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id', 'blog_id' ),
				'properties'           => array(
					'user_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the user to remove from the site. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
					'blog_id'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The site (blog) ID to remove the user from. Discover IDs with network/list-sites.', 'abilities-catalog' ),
					),
					'reassign' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'Optional. The ID of a user to reassign the removed user\'s posts to. 0 (the default) reassigns nothing (the posts keep their author).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'removed', 'user_id', 'blog_id' ),
				'properties'           => array(
					'removed' => array(
						'type'        => 'boolean',
						'description' => __( 'True once the user no longer has membership/roles on the site. The user\'s account itself is unaffected.', 'abilities-catalog' ),
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'The user ID that was removed, echoed back.', 'abilities-catalog' ),
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'The site (blog) ID the user was removed from, echoed back.', 'abilities-catalog' ),
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
				),
				'show_in_rest' => true,
				'screen'       => 'site-users.php',
			),
		);
	}

	/**
	 * Permission check: multisite, and the current user may manage sites.
	 *
	 * The hard guard is `is_multisite() && current_user_can( 'manage_sites' )`.
	 * `manage_sites` is a network (super-admin) capability; a plain site
	 * administrator does not hold it, and on a single site it is granted to no
	 * one — correct, since the ability is meaningless there. This is an ordinary
	 * (non-dangerous) write, so the cap is enforced here only and is NOT repeated
	 * at the top of execute(). The guard is object-independent: a missing user or
	 * site surfaces as the specific 404 from execute(), never as a denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by removing the user's membership from the site.
	 *
	 * Validates the site and the user exist (honest 404s) before the mutation,
	 * then wraps `remove_user_from_blog()` (param order: user, blog, reassign).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The removal result, a 400 on single-site, or a 404 if the user/site is missing.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$input    = is_array( $input ) ? $input : array();
		$user_id  = absint( $input['user_id'] ?? 0 );
		$blog_id  = absint( $input['blog_id'] ?? 0 );
		$reassign = absint( $input['reassign'] ?? 0 );

		if ( ! get_site( $blog_id ) instanceof WP_Site ) {
			return new WP_Error(
				'rest_site_invalid_id',
				__( 'Invalid site ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'rest_user_invalid_id',
				__( 'Invalid user ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$result = remove_user_from_blog( $user_id, $blog_id, $reassign );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 404 )
			);
		}

		return array(
			'removed' => true,
			'user_id' => $user_id,
			'blog_id' => $blog_id,
		);
	}
}
