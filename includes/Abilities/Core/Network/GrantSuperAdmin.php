<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `og-network/grant-super-admin`.
 *
 * Grants a user network-wide super-admin privileges — full control of every site
 * in the multisite network and all network settings. Wraps
 * `grant_super_admin( $user_id )` (wp-includes/capabilities.php:1215 → bool) and
 * then reads the authoritative end state with `is_super_admin( $user_id )`
 * (wp-includes/capabilities.php:1181).
 *
 * Runtime semantics (verified against core):
 * - `grant_super_admin()` returns `true` only when this call actually adds the
 *   user's login to the super-admin list (capabilities.php:1234-1246).
 * - It returns `false` for benign states, NOT only on failure: when the user is
 *   ALREADY a super admin, and when the `$super_admins` global is pinned in
 *   wp-config (or the install is not multisite) (capabilities.php:1217). So a
 *   `false` `granted` is not surfaced as an error — `is_super_admin()` after the
 *   call is the truth, and is returned as `is_super_admin` for the caller to
 *   branch on.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it mutates the network `site_admins`
 *   option).
 * - `destructive` is false: reversible via `og-network/revoke-super-admin`.
 * - `idempotent` is true: granting an already-super-admin is a same-state no-op
 *   (`granted:false`, `is_super_admin:true`).
 * - `dangerous` is true: this is a privilege escalation granting network-wide
 *   control. There is no `Support/` guard (no filesystem/source/upgrader/
 *   option-allow-list risk class applies); the hard guard is
 *   `manage_network_users` plus the existence pre-check in {@see self::execute()}.
 *   The Registry auto-lists any `dangerous` ability in the
 *   `abilities_catalog_dangerous_tools` filter.
 *
 * Multisite only: `grant_super_admin()` reads/writes network-scoped state, so
 * execute() begins with an explicit `is_multisite()` guard before touching it,
 * and the permission gate also requires `is_multisite()`.
 *
 * Security note: `grant_super_admin()` performs NO capability check of its own.
 * The `permission_callback` plus the explicit
 * `current_user_can( 'manage_network_users' )` check at the top of
 * {@see self::execute()} are the only authorization guards.
 *
 * @since 0.1.0
 */
final class GrantSuperAdmin implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-network/grant-super-admin';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Grant Super Admin', 'abilities-catalog' ),
			'description'         => __( 'Grants a user NETWORK-WIDE super-admin privileges: full control of every site in the network and all network settings. This is a privilege escalation and a dangerous operation; reverse it with og-network/revoke-super-admin. is_super_admin in the result is the authoritative end state — granted may be false when the user was already a super admin (a no-op) or when the site pins its super-admin list in wp-config (which this tool cannot change). An unknown user_id returns a 404. Requires a multisite install and the manage_network_users (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'network',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'user_id' ),
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of an existing user to grant super-admin privileges to. Discover IDs with og-users/list-users.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'granted', 'user_id', 'is_super_admin' ),
				'properties'           => array(
					'granted'        => array(
						'type'        => 'boolean',
						'description' => __( 'True if this call added the user to the super-admin list. False can mean the user was already a super admin (check is_super_admin), or the site defines a fixed super-admin list in wp-config that this tool cannot change.', 'abilities-catalog' ),
					),
					'user_id'        => array(
						'type'        => 'integer',
						'description' => __( 'The user ID, echoed back.', 'abilities-catalog' ),
					),
					'is_super_admin' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the user is a super admin AFTER this call (the authoritative result — use this, not granted, to confirm the end state).', 'abilities-catalog' ),
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
	 * This is the hard server-side guard. It is object-independent (no DB lookups):
	 * the existence check lives in {@see self::execute()} so it surfaces a specific
	 * 404 instead of collapsing into a generic permission denial.
	 * `manage_network_users` is required because granting super-admin mutates
	 * network-scoped state; a super admin holds it and a plain site administrator
	 * does not. On a single site `is_multisite()` is false, so this returns false
	 * for everyone — correct, since the ability is meaningless there.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if multisite and the current user can manage network users.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network_users' );
	}

	/**
	 * Executes the ability by granting super-admin privileges and reading back the
	 * authoritative end state.
	 *
	 * The explicit `current_user_can( 'manage_network_users' )` check is repeated
	 * here, at the top and before any mutation, because `grant_super_admin()`
	 * performs no capability check of its own and there is no wrapped route to
	 * surface a denial. This branch is normally unreachable (the
	 * `permission_callback` blocks first) but keeps execute() safe if ever called
	 * directly.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The grant result, or a WP_Error.
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
				__( 'You are not allowed to manage network users.', 'abilities-catalog' ),
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

		$granted        = grant_super_admin( $user_id );
		$is_super_admin = is_super_admin( $user_id );

		return array(
			'granted'        => (bool) $granted,
			'user_id'        => $user_id,
			'is_super_admin' => (bool) $is_super_admin,
		);
	}
}
