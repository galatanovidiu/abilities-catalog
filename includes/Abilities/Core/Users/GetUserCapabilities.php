<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composed read ability: `users/get-user-capabilities`.
 *
 * Returns one user's EFFECTIVE capability set and role slugs by user ID. The
 * effective set is more than the role list: core resolves role-derived caps and
 * per-user grants together. Built on `get_userdata()` (`WP_User`) rather than a
 * REST route, because the REST users controller exposes the resolved `allcaps`
 * map only in `edit` context (`class-wp-rest-users-controller.php` declares
 * `capabilities`/`extra_capabilities` with `'context' => array('edit')`), and
 * core exposes no dedicated route for the resolved cap map alone. All functions
 * used live in wp-includes, so no wp-admin includes are loaded.
 *
 * Distinct from `users/get-user`, which returns the role list (and the raw
 * `allcaps` object only in edit context); this ability resolves and flattens the
 * granted effective caps into a sorted string list.
 *
 * ROLE-SLUG FILTERING: `WP_User::get_role_caps()` merges the user's own caps
 * (`$this->caps`) into `$allcaps` (class-wp-user.php line ~535), and `$this->caps`
 * stores each assigned role slug as a key set to `true` (e.g. `editor => true`).
 * So `allcaps` carries each role slug as a truthy "capability" alongside the real
 * caps. This ability filters those role slugs out of `capabilities` — using the
 * same `wp_roles()->is_role()` test core uses to separate roles from caps
 * (class-wp-user.php line ~523) — so `capabilities` is the true effective cap set
 * and the role slugs are surfaced only in `roles`.
 *
 * @since 0.1.0
 */
final class GetUserCapabilities implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/get-user-capabilities';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get User Capabilities', 'abilities-catalog' ),
			'description'         => __( 'Returns one user\'s effective capabilities and roles by ID. The capability list is the fully resolved set — role-derived capabilities and per-user grants merged together — as a sorted list of granted capability names; role slugs that core mixes into the map are filtered out. Use this when you need the actual permissions a user holds; use users/get-user for the user profile and its role list, which does not resolve the effective capability set.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'id' ),
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The user ID. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'id', 'roles', 'capabilities' ),
				'properties'           => array(
					'id'           => array(
						'type'        => 'integer',
						'description' => __( 'The user ID.', 'abilities-catalog' ),
					),
					'roles'        => array(
						'type'        => 'array',
						'description' => __( 'The user\'s role slugs (e.g. "editor"). Empty if the user holds no role.', 'abilities-catalog' ),
						'items'       => array(
							'type' => 'string',
						),
					),
					'capabilities' => array(
						'type'        => 'array',
						'description' => __( 'The user\'s granted effective capabilities, as a sorted list of capability-name strings. Includes both role-derived and per-user capabilities; only granted caps are listed (caps explicitly set to false are omitted), and role slugs are excluded.', 'abilities-catalog' ),
						'items'       => array(
							'type' => 'string',
						),
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
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Permission check: the current user may edit users (`edit_users`).
	 *
	 * A user's full, resolved capability set is edit-context data in core: the
	 * REST users controller serves `capabilities`/`extra_capabilities` only in
	 * `edit` context (`class-wp-rest-users-controller.php`), which for an arbitrary
	 * user requires `edit_user` and, network-wide, the `edit_users` meta-cap. This
	 * ability targets arbitrary users by ID, so it gates on the coarse `edit_users`
	 * baseline that every successful caller must hold; it is not weaker than core,
	 * which never exposes this map to a caller who cannot edit the target.
	 *
	 * `list_users` is deliberately too weak: it lets a caller see a user's role
	 * (as `users/get-user` and the Users screen show), but not the resolved
	 * effective capability set this ability returns. Reading one's OWN effective
	 * caps is already served by `users/get-current-user`, so this ability is for
	 * arbitrary users and `edit_users` is the correct coarse guard.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can edit users.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'edit_users' );
	}

	/**
	 * Executes the ability by resolving the user's roles and effective caps.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error Flat {id, roles, capabilities}, or a 404.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );

		$user = get_userdata( $id );
		if ( false === $user ) {
			return new WP_Error(
				'rest_user_invalid_id',
				__( 'Invalid user ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$wp_roles = wp_roles();

		$capabilities = array();
		foreach ( (array) $user->allcaps as $cap => $granted ) {
			if ( ! $granted ) {
				continue;
			}
			$cap = (string) $cap;
			// Core mixes role slugs into allcaps as truthy keys; surface them only
			// in `roles`, never as capabilities.
			if ( $wp_roles->is_role( $cap ) ) {
				continue;
			}
			$capabilities[] = $cap;
		}
		sort( $capabilities );

		return array(
			'id'           => (int) $user->ID,
			'roles'        => array_map( 'strval', (array) $user->roles ),
			'capabilities' => $capabilities,
		);
	}
}
