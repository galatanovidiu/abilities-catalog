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
 * Ordinary core-function write ability: `network/add-user-to-site`.
 *
 * Adds an existing user to a site (blog) in a multisite network with a role —
 * membership only, the user account already exists. Mirrors the Network Admin ->
 * Sites -> Users -> Add Existing User action. Built on `add_user_to_blog()`
 * (wp-includes/ms-functions.php:161 -> `true|WP_Error`) since core exposes no REST
 * route for site membership; no wp-admin includes are loaded (`add_user_to_blog`
 * is a wp-includes function).
 *
 * `add_user_to_blog()` calls `$user->set_role( $role )` on that site, which
 * REPLACES the user's roles on the site, so re-adding the same user updates their
 * role rather than appending. That makes the operation idempotent in the sense
 * that re-running with the same role yields the same end state -> `idempotent:true`.
 *
 * Classification rationale:
 * - `readonly` false: this is a write (it grants membership/role on a site).
 * - `destructive` false: membership is reversible (network/remove-user-from-site),
 *   and the user account itself is untouched.
 * - `idempotent` true: re-adding with the same role leaves the same end state.
 * - NOT `dangerous`: site membership is an ordinary super-admin write, not a
 *   code/filesystem/privilege-escalation operation. The coarse `manage_sites`
 *   `permission_callback` is the hard guard; no execute()-top cap repeat is needed.
 *
 * Multisite only: the membership tables do not exist on a single site, so
 * `execute()` returns a 400 before touching any `ms-*` function, mirroring the
 * "explicit guard at the top of execute() when the wrapped core fn has no route to
 * surface an error" idiom (`tools/delete-transient`, `network/get-site`).
 *
 * Security note: `add_user_to_blog()` performs NO capability check of its own. The
 * `permission_callback` (`is_multisite() && current_user_can( 'manage_sites' )`,
 * the network/super-admin capability) is the only authorization guard. Because this
 * is an ordinary (non-dangerous) write, the cap is enforced once in the callback,
 * not repeated in execute(); the in-execute() checks here are object-existence
 * validations (site/user/role), which surface honest 404/400 errors rather than a
 * permission collapse.
 *
 * @since 0.1.0
 */
final class AddUserToSite implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/add-user-to-site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Add User to Site', 'abilities-catalog' ),
			'description'         => __( 'Adds an existing user to a site (blog) in a multisite network with a given role (membership only — the user account already exists). Re-adding a user updates their role on that site, so this is idempotent. blog_id, user_id, and role must all be valid: discover sites with network/list-sites, users with users/list-users, and role slugs with users/list-roles. An unknown site or user returns a 404; an unknown role returns a 400. Remove a user with network/remove-user-from-site. Requires a multisite install and the manage_sites (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'blog_id', 'user_id', 'role' ),
				'properties'           => array(
					'blog_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The site (blog) ID to add the user to. Discover IDs with network/list-sites.', 'abilities-catalog' ),
					),
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of an EXISTING user to add. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
					'role'    => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The role slug to assign on that site, e.g. "editor" or "author". Discover valid slugs with users/list-roles. An unknown role is rejected with a 400.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'added', 'blog_id', 'user_id', 'role' ),
				'properties'           => array(
					'added'   => array(
						'type'        => 'boolean',
						'description' => __( 'True once the user holds the role on the site.', 'abilities-catalog' ),
					),
					'blog_id' => array(
						'type'        => 'integer',
						'description' => __( 'The site (blog) ID the user was added to, echoed back.', 'abilities-catalog' ),
					),
					'user_id' => array(
						'type'        => 'integer',
						'description' => __( 'The ID of the user that was added, echoed back.', 'abilities-catalog' ),
					),
					'role'    => array(
						'type'        => 'string',
						'description' => __( 'The role the user now holds on that site.', 'abilities-catalog' ),
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
	 * administrator does not hold it, and on a single site it is granted to no one —
	 * correct, since the ability is meaningless there. `add_user_to_blog()` checks
	 * no capability, so this callback is the only authorization guard. The guard is
	 * object-independent: an unknown blog_id/user_id/role surfaces as a specific
	 * 404/400 from execute(), never as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage sites.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_sites' );
	}

	/**
	 * Executes the ability by adding the user to the site with the given role.
	 *
	 * Validates that the site, user, and role exist before mutating, returning a
	 * specific 404/400 for each so the agent gets an actionable error instead of a
	 * filter rejection deep inside core.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The membership result, a 400 on single-site, or a 404/400 on a missing object/role.
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
		$user_id = absint( $input['user_id'] ?? 0 );
		$role    = (string) ( $input['role'] ?? '' );

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

		if ( '' === $role || ! get_role( $role ) ) {
			return new WP_Error(
				'abilities_catalog_invalid_role',
				__( 'Unknown role. Discover valid role slugs with users/list-roles.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$result = add_user_to_blog( $blog_id, $user_id, $role );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return array(
			'added'   => true,
			'blog_id' => $blog_id,
			'user_id' => $user_id,
			'role'    => $role,
		);
	}
}
