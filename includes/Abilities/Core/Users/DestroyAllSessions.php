<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Users;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_Session_Tokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `og-users/destroy-all-sessions`.
 *
 * Ends ALL of a user's login sessions on every device by wrapping core
 * `WP_Session_Tokens::get_instance( $user_id )->destroy_all()`, which clears the
 * user's stored `session_tokens` meta. With no `user_id` (or 0) it targets the
 * current user — logging the caller out too; with another `user_id` it is an admin
 * force-logout (e.g. for a compromised account).
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it removes the user's stored session
 *   tokens).
 * - `destructive` is false: sessions are not a source of truth — they are
 *   self-healing, since the user (or admin) restores access simply by logging back
 *   in — so ending them is not irreversible data loss. (It is a write, so the
 *   boolean must still be declared, which is why it is present and set to false.)
 * - `idempotent` is true: running it again once all sessions are gone leaves the
 *   same end state (no active sessions).
 * - `dangerous` is true: the blast radius is WIDE — it logs the user out on every
 *   device at once, and when targeting yourself it ends your own session. There is
 *   no `Support/` guard (no filesystem/source/upgrader/option-allow-list risk class
 *   applies — the cron/flush precedent: operational-risk dangerous ops need none);
 *   the hard guard is the object-independent `edit_users` capability plus the
 *   explicit checks at the top of {@see self::execute()}. The Registry auto-lists
 *   any `dangerous` ability in the `abilities_catalog_dangerous_tools` filter.
 *
 * No `meta.screen` is set: ending sessions has no dedicated wp-admin screen for a
 * consumer to deep-link.
 *
 * Security note: core's `WP_Session_Tokens::destroy_all()` performs NO capability
 * check of its own. The `permission_callback` enforces the coarse, object-independent
 * `edit_users` baseline (the dangerous-tier gate that denies subscribers); the precise
 * per-target guard — `rest_user_invalid_id` (404) for a missing user and the
 * object-level `edit_user( $uid )` (403) for another user the caller cannot edit —
 * lives in {@see self::execute()}, so those errors are not masked as a generic
 * permission denial.
 *
 * @since 0.7.0
 */
final class DestroyAllSessions implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/destroy-all-sessions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Destroy All Sessions', 'abilities-catalog' ),
			'description'         => __( 'Ends ALL of a user\'s login sessions on every device, forcing a fresh login everywhere. With no user_id (or 0) this targets the CURRENT user and logs you out too. For another user it is an admin force-logout (e.g. a compromised account). Requires the edit_users capability. Reversible only by logging back in.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'The user whose sessions to end. Omit or pass 0 to target the current user (which logs you out too). Discover IDs with og-users/list-users.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'destroyed', 'user_id', 'sessions_ended' ),
				'properties'           => array(
					'destroyed'      => array(
						'type'        => 'boolean',
						'description' => __( 'Always true on success: every session for the user was ended.', 'abilities-catalog' ),
					),
					'user_id'        => array(
						'type'        => 'integer',
						'description' => __( 'The user whose sessions were ended (the resolved target — the current user when 0 or omitted was passed).', 'abilities-catalog' ),
					),
					'sessions_ended' => array(
						'type'        => 'integer',
						'description' => __( 'How many active sessions existed and were ended, counted before the operation. 0 means the user had no active sessions (still a success, not an error).', 'abilities-catalog' ),
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
					'scope' => 'user',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to edit users.
	 *
	 * `edit_users` is the object-independent, dangerous-tier baseline: it denies
	 * subscribers cleanly and is the capability the dangerous-tier permission test
	 * asserts. It is deliberately coarse — the precise per-target checks
	 * (`rest_user_invalid_id` 404 for a missing user, object-level `edit_user( $uid )`
	 * 403 for another user) live in {@see self::execute()}, because the Abilities API
	 * would collapse a non-true return here into one generic denial and hide which of
	 * the two it was. On multisite core's `map_meta_cap()` resolves `edit_users` /
	 * `edit_user` correctly, so no explicit network check is added.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may edit users.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'edit_users' );
	}

	/**
	 * Executes the ability by ending every session for the target user.
	 *
	 * The explicit `edit_users` check is repeated here, at the top and before any
	 * mutation, because the wrapped core function performs no capability check of its
	 * own. The session count is captured before the destroy so `sessions_ended`
	 * reports the real number ended.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,bool|int>|\WP_Error The result, or a WP_Error.
	 */
	public function execute( $input ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_users',
				__( 'You are not allowed to end user sessions.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input = is_array( $input ) ? $input : array();
		$uid   = absint( $input['user_id'] ?? 0 ) ?: get_current_user_id();

		if ( ! get_userdata( $uid ) ) {
			return new WP_Error(
				'rest_user_invalid_id',
				__( 'Invalid user ID.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		if ( $uid !== get_current_user_id() && ! current_user_can( 'edit_user', $uid ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to end this user\'s sessions.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$manager = WP_Session_Tokens::get_instance( $uid );
		$count   = count( $manager->get_all() );

		$manager->destroy_all();

		return array(
			'destroyed'      => true,
			'user_id'        => $uid,
			'sessions_ended' => $count,
		);
	}
}
