<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Network;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-network/list-super-admins`.
 *
 * Lists a multisite network's super admins (the users with global network
 * control), resolving each stored login to its user id, email, and display
 * name, so an agent can audit who holds full control over the whole network.
 *
 * Wraps `get_super_admins()` (wp-includes/capabilities.php:1163), which returns
 * a `string[]` of user_login strings (it falls back to the `site_admins` site
 * option, default `array( 'admin' )`, when the `$super_admins` global is unset).
 * Each login is resolved with `get_user_by( 'login', $login )`.
 *
 * Resolution policy: a login that resolves to a `WP_User` emits its `user_id`,
 * `user_email`, and `display_name`; a login that does NOT resolve (a stale entry
 * left in the `site_admins` option for a deleted user) still emits a row with
 * `user_login` set, `user_id => 0`, and empty `user_email`/`display_name`, so the
 * audit surface shows the stale login rather than silently dropping it.
 *
 * Multisite only: `get_super_admins()` reads network-scoped state, so execute()
 * begins with an explicit `is_multisite()` guard (returns a 400 `WP_Error`)
 * before touching it, and the permission gate also requires `is_multisite()`.
 *
 * @since 0.1.0
 */
final class ListSuperAdmins implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-network/list-super-admins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Super Admins', 'abilities-catalog' ),
			'description'         => __( 'Lists the multisite network\'s super admins (users with global network control), each with user_login, user_id, user_email, and display_name. A row with user_id 0 is a stale login in the super-admin list whose user no longer exists. Requires a multisite install and the manage_network_users (super-admin) capability.', 'abilities-catalog' ),
			'category'            => 'og-core-network',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'super_admins', 'total' ),
				'properties'           => array(
					'super_admins' => array(
						'type'        => 'array',
						'description' => __( 'The network\'s super admins, one flat row per super admin.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'user_login', 'user_id', 'user_email', 'display_name' ),
							'properties'           => array(
								'user_login'   => array(
									'type'        => 'string',
									'description' => __( 'The user\'s login name (the value stored in the super-admin list).', 'abilities-catalog' ),
								),
								'user_id'      => array(
									'type'        => 'integer',
									'description' => __( 'The user ID, or 0 if the login no longer resolves to an existing user (a stale entry).', 'abilities-catalog' ),
								),
								'user_email'   => array(
									'type'        => 'string',
									'description' => __( 'The user\'s email, or empty if unresolved.', 'abilities-catalog' ),
								),
								'display_name' => array(
									'type'        => 'string',
									'description' => __( 'The user\'s display name, or empty if unresolved.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'        => array(
						'type'        => 'integer',
						'description' => __( 'The number of super admins listed.', 'abilities-catalog' ),
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
	 * Permission check: a network user manager on a multisite install.
	 *
	 * Super-admin membership is network-user data and the listed emails are PII,
	 * so this read is gated on the network-users capability (`manage_network_users`),
	 * which a super admin holds and a plain site administrator does not. On a
	 * single site `is_multisite()` is false, so this returns false for everyone —
	 * correct, since the ability is meaningless there.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return bool True if multisite and the current user can manage network users.
	 */
	public function hasPermission( $input = null ): bool {
		return is_multisite() && current_user_can( 'manage_network_users' );
	}

	/**
	 * Executes the ability by resolving each super-admin login to a flat row.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return array<string,mixed>|\WP_Error The super admins list and total count,
	 *                                       or a 400 error on a single-site install.
	 */
	public function execute( $input = null ) {
		if ( ! is_multisite() ) {
			return new WP_Error(
				'abilities_catalog_requires_multisite',
				__( 'This ability requires a WordPress multisite (network) installation.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$logins = get_super_admins();
		if ( ! is_array( $logins ) ) {
			$logins = array();
		}

		$super_admins = array();

		foreach ( $logins as $login ) {
			$user = get_user_by( 'login', (string) $login );

			$super_admins[] = array(
				'user_login'   => (string) $login,
				'user_id'      => $user instanceof WP_User ? (int) $user->ID : 0,
				'user_email'   => $user instanceof WP_User ? (string) $user->user_email : '',
				'display_name' => $user instanceof WP_User ? (string) $user->display_name : '',
			);
		}

		return array(
			'super_admins' => $super_admins,
			'total'        => count( $super_admins ),
		);
	}
}
