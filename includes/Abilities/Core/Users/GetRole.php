<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed T1 read ability: `og-users/get-role`.
 *
 * Returns a single registered WordPress role by its slug with its human display
 * name, the capabilities granted to it, and the number of users currently in
 * that role. This is the single-object companion to `og-users/list-roles`. Built
 * directly on core role and counting functions (`wp_roles()`, `count_users()`)
 * rather than REST, since core exposes no REST route for the role/capability
 * map. All three functions live in wp-includes, so no wp-admin includes are
 * loaded.
 *
 * `count_users()` returns `avail_roles` keyed by role slug, but it omits roles
 * with zero users (and adds a synthetic `none` bucket), so the role's count is
 * resolved with a default of 0 rather than read blindly.
 *
 * @since 0.1.0
 */
final class GetRole implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/get-role';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Role', 'abilities-catalog' ),
			'description'         => __( 'Returns a single registered user role by its slug, including its display name, the capabilities granted to it, and the number of users currently assigned to it. Single-role read; use og-users/list-roles to enumerate every role and discover slugs, or og-users/list-users to enumerate the users themselves.', 'abilities-catalog' ),
			'category'            => 'og-core-users',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'slug' ),
				'properties'           => array(
					'slug' => array(
						'type'        => 'string',
						'description' => __( 'The role identifier used by core (e.g. "editor", "administrator"). Discover slugs with og-users/list-roles.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'slug', 'name', 'capabilities', 'user_count' ),
				'properties'           => array(
					'slug'         => array(
						'type'        => 'string',
						'description' => __( 'The role identifier used by core (e.g. "administrator", "editor"). Pass this to og-users/create-user or og-users/update-user as the role value.', 'abilities-catalog' ),
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
	 * Reading a role together with its granted capabilities exposes the site's
	 * permission model, so this gates on `list_users` — the same capability the
	 * wp-admin Users screen (`wp-admin/users.php`) checks before rendering. This
	 * is not weaker than core for this data: a user who can open the Users screen
	 * can already see the role list and assigned capabilities there. The guard is
	 * object-independent; an unknown slug surfaces as the specific 404 from
	 * execute(), never as a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can list users.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'list_users' );
	}

	/**
	 * Executes the ability by reading one role and its per-role user count.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The role row, or a 404 if the slug is unknown.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();
		$slug  = (string) ( $input['slug'] ?? '' );

		$wp_roles = wp_roles();
		$role     = $wp_roles->get_role( $slug );

		if ( null === $role ) {
			return new WP_Error(
				'abilities_catalog_role_not_found',
				sprintf(
					/* translators: %s: the requested role slug. */
					__( 'No registered role exists with the slug "%s".', 'abilities-catalog' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		$capabilities = is_array( $role->capabilities ) ? $role->capabilities : array();

		$granted = array();
		foreach ( $capabilities as $cap => $granted_value ) {
			if ( ! $granted_value ) {
				continue;
			}
			$granted[] = (string) $cap;
		}
		sort( $granted );

		$counts      = count_users();
		$avail_roles = isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] )
			? $counts['avail_roles']
			: array();

		$role_names = $wp_roles->role_names;

		return array(
			'slug'         => $slug,
			'name'         => (string) ( $role_names[ $slug ] ?? $slug ),
			'capabilities' => $granted,
			'user_count'   => (int) ( $avail_roles[ $slug ] ?? 0 ),
		);
	}
}
