<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed T1 read ability: `users/list-roles`.
 *
 * Lists every registered WordPress role with its slug, human display name, the
 * capabilities granted to it, and the number of users currently in that role.
 * Built directly on core role and counting functions (`wp_roles()`,
 * `count_users()`) rather than REST, since core exposes no REST route for the
 * role/capability map. All three functions live in wp-includes, so no
 * wp-admin includes are loaded.
 *
 * `count_users()` returns `avail_roles` keyed by role slug, but it omits roles
 * with zero users (and adds a synthetic `none` bucket), so each role's count is
 * resolved with a default of 0 rather than read blindly.
 *
 * @since 0.1.0
 */
final class ListRoles implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/list-roles';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Roles', 'abilities-catalog' ),
			'description'         => __( 'Lists every registered user role with its slug, display name, the capabilities granted to it, and the number of users currently assigned to it. Read-only view of the site permission model; use users/list-users to enumerate the users themselves.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'roles', 'total' ),
				'properties'           => array(
					'roles' => array(
						'type'        => 'array',
						'description' => __( 'All registered roles, one row per role.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'slug', 'name', 'capabilities', 'user_count' ),
							'properties'           => array(
								'slug'         => array(
									'type'        => 'string',
									'description' => __( 'The role identifier used by core (e.g. "administrator", "editor"). Pass this to users/create-user or users/update-user as the role value.', 'abilities-catalog' ),
								),
								'name'         => array(
									'type'        => 'string',
									'description' => __( 'The human-readable display name for the role (e.g. "Administrator").', 'abilities-catalog' ),
								),
								'capabilities' => array(
									'type'        => 'array',
									'description' => __( 'The capabilities granted to this role, as a sorted list of capability-name strings. Only granted capabilities are listed; capabilities explicitly set to false (denied) are omitted.', 'abilities-catalog' ),
									'items'       => array(
										'type' => 'string',
									),
								),
								'user_count'   => array(
									'type'        => 'integer',
									'description' => __( 'The number of users currently assigned to this role on this site. 0 means no users hold the role.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total' => array(
						'type'        => 'integer',
						'description' => __( 'The number of registered roles returned (the length of the roles array).', 'abilities-catalog' ),
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
	 * Permission check: the current user may list users (`list_users`).
	 *
	 * Listing roles together with their granted capabilities exposes the site's
	 * permission model, so this gates on `list_users` — the same capability the
	 * wp-admin Users screen (`wp-admin/users.php`) checks before rendering. This
	 * is not weaker than core for this data: a user who can open the Users screen
	 * can already see the role list and assigned capabilities there.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can list users.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'list_users' );
	}

	/**
	 * Executes the ability by reading the registered roles and per-role counts.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The roles list and total count.
	 */
	public function execute( $input = null ) {
		$wp_roles    = wp_roles();
		$role_names  = $wp_roles->role_names;
		$counts      = count_users();
		$avail_roles = isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] )
			? $counts['avail_roles']
			: array();

		$roles = array();

		foreach ( $wp_roles->roles as $slug => $role ) {
			$slug         = (string) $slug;
			$capabilities = isset( $role['capabilities'] ) && is_array( $role['capabilities'] )
				? $role['capabilities']
				: array();

			$granted = array();
			foreach ( $capabilities as $cap => $granted_value ) {
				if ( ! $granted_value ) {
					continue;
				}
				$granted[] = (string) $cap;
			}
			sort( $granted );

			$roles[] = array(
				'slug'         => $slug,
				'name'         => (string) ( $role_names[ $slug ] ?? ( $role['name'] ?? $slug ) ),
				'capabilities' => $granted,
				'user_count'   => (int) ( $avail_roles[ $slug ] ?? 0 ),
			);
		}

		return array(
			'roles' => $roles,
			'total' => count( $roles ),
		);
	}
}
