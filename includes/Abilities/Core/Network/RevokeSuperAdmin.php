<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `network/revoke-super-admin`.
 *
 * Removes a user's network-wide super-admin privileges by wrapping core
 * `revoke_super_admin( $user_id )` (wp-includes/capabilities.php:1263), then
 * reads the authoritative end state back with `is_super_admin( $user_id )`
 * (wp-includes/capabilities.php:1181). The inverse of
 * `network/grant-super-admin`.
 *
 * Core return semantics (verified against wp-includes/capabilities.php:1263):
 * `revoke_super_admin()` returns a bare `bool`. It returns `false` — WITHOUT it
 * being an error — when the `$super_admins` global is defined in wp-config or the
 * install is not multisite (:1265), and when the user is NOT currently a super
 * admin (:1299). On WP 6.9.0+ the old "blocked when the user's email is the
 * network admin email" safeguard was REMOVED (the `@since 6.9.0` note at :1255;
 * the docblock's email-block sentence is stale), so core does NOT block revoking
 * the last super admin or your own privileges. This ability therefore reports the
 * post-call truth from `is_super_admin()` instead of trusting the raw bool, and
 * surfaces a benign `revoked:false` no-op rather than an error.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it changes the `site_admins` site option).
 * - `destructive` is false: revoking is reversible via `network/grant-super-admin`.
 * - `idempotent` is true: revoking a non-super-admin is a same-state no-op.
 * - `dangerous` is true: this changes who controls the whole network and can lock
 *   out the last remaining super admin. There is no `Support/` guard (no
 *   filesystem/source/upgrader/option-allow-list risk class applies); the hard
 *   guard is `manage_network_users` plus the existence pre-check in
 *   {@see self::execute()}. The Registry auto-lists any `dangerous` ability in the
 *   `abilities_catalog_dangerous_tools` filter.
 *
 * Multisite only: `revoke_super_admin()` reads/writes network-scoped state, so
 * execute() begins with an explicit `is_multisite()` guard (returns a 400
 * `WP_Error`) before touching it, and the permission gate also requires
 * `is_multisite()`.
 *
 * Security note: core's `revoke_super_admin()` performs NO capability check of its
 * own. The `permission_callback` plus the explicit
 * `current_user_can( 'manage_network_users' )` check at the top of
 * {@see self::execute()} are the only authorization guards.
 *
 * @since 0.1.0
 */
final class RevokeSuperAdmin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'network/revoke-super-admin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Revoke Super Admin', 'abilities-catalog' ),
			'description'         => __( 'Revokes a user\'s NETWORK-WIDE super-admin privileges. This is a dangerous operation: reverse it with network/grant-super-admin. is_super_admin in the result is the authoritative end state — revoked may be false when the user was not a super admin (a no-op) or when the site pins its super-admin list in wp-config (which this tool cannot change). Caution: WordPress does not stop you from revoking the last remaining super admin or your own privileges, which can lock the network\'s super-admin functions; verify another super admin remains (network/list-super-admins) first. An unknown user_id returns a 404. Requires a multisite install and the manage_network_users (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id' ),
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the user to revoke super-admin privileges from. Discover IDs with network/list-super-admins.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'revoked', 'user_id', 'is_super_admin' ),
				'properties'           => array(
					'revoked'        => array(
						'type'        => 'boolean',
						'description' => __( 'True if this call removed the user from the super-admin list. False can mean the user was not a super admin (a no-op), or the site pins its super-admin list in wp-config.', 'abilities-catalog' ),
					),
					'user_id'        => array(
						'type'        => 'integer',
						'description' => __( 'The user ID, echoed back.', 'abilities-catalog' ),
					),
					'is_super_admin' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the user is a super admin AFTER this call (the authoritative end state).', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
					'dangerous'   => true,
				),
				'abilities_catalog' => array(
					'scope' => 'network',
				),
				'show_in_rest'      => true,
				'screen'            => 'users.php',
			),
		);
	}

	/**
	 * Permission check: a network user manager on a multisite install.
	 *
	 * This is the hard server-side guard and is object-independent (no user
	 * lookup): the existence check lives in {@see self::execute()} so a missing
	 * user_id surfaces a specific 404 instead of collapsing into a generic
	 * permission denial. `manage_network_users` is the network cap a super admin
	 * holds and a plain site administrator does not. On a single site
	 * `is_multisite()` is false, so this returns false for everyone — correct,
	 * since the ability is meaningless there.
	 *
	 * @param mixed $input The validated input data (unused for the gate).
	 * @return bool True if multisite and the current user can manage network users.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network_users' );
	}

	/**
	 * Executes the ability by revoking the user's super-admin privileges.
	 *
	 * The explicit `current_user_can( 'manage_network_users' )` check is repeated
	 * here, at the top and before any mutation, because the wrapped
	 * `revoke_super_admin()` performs no capability check of its own. After the
	 * call, `is_super_admin()` is read back to report the authoritative end state;
	 * a `false` return from `revoke_super_admin()` is reported as a benign
	 * `revoked:false` no-op (the user was not a super admin, or the site pins its
	 * super-admin list in wp-config), never as an error.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The revoke result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		if ( ! current_user_can( 'manage_network_users' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_network_users',
				__( 'You are not allowed to revoke super-admin privileges.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input   = is_array( $input ) ? $input : array();
		$user_id = absint( $input['user_id'] ?? 0 );

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'rest_user_invalid_id',
				__( 'Invalid user ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$revoked        = revoke_super_admin( $user_id );
		$is_super_admin = is_super_admin( $user_id );

		return array(
			'revoked'        => (bool) $revoked,
			'user_id'        => $user_id,
			'is_super_admin' => $is_super_admin,
		);
	}
}
