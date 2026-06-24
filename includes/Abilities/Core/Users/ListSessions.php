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
 * T1 read ability: `users/list-sessions`.
 *
 * Lists a user's active login sessions — one row per device/login that is
 * still valid — so an agent can audit where a user is currently logged in.
 * Defaults to the current user when `user_id` is omitted or 0.
 *
 * Wraps core `WP_Session_Tokens::get_instance( $uid )->get_all()`, which
 * returns the still-valid sessions for the user (expired tokens are filtered
 * out by core). Each raw session carries `expiration` and `login` Unix
 * timestamps and, when the session was created over a cookie login, the `ip`
 * and `ua` (user-agent) it was created from; legacy tokens may carry only
 * `expiration`. This ability projects every row to a fixed, closed shape,
 * renaming `ua` to `user_agent` and defaulting any absent field.
 *
 * Sessions expose the IP address and user-agent a user logged in from, so this
 * read requires object-level `edit_user` for anyone but the user themselves —
 * the same reason `users/get-meta` requires `edit_user`. The coarse
 * `permission_callback` only enforces that the caller is logged in; the
 * object-level guard lives in `execute()` so a missing user surfaces a specific
 * `rest_user_invalid_id` (404) instead of being masked as a permission denial.
 *
 * @since 0.7.0
 */
final class ListSessions implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'users/list-sessions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Sessions', 'abilities-catalog' ),
			'description'         => __( 'Returns a user\'s active login sessions (one row per device/login), each with its expiration, login time, IP address, and user-agent. With no user_id (or 0) it lists the current user\'s own sessions; listing another user\'s sessions requires edit access to that user, since the rows expose IP and user-agent. Only still-valid (unexpired) sessions are returned.', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'user_id' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 0,
						'description' => __( 'The user whose sessions to list. Omit or pass 0 for the current user. Discover IDs with users/list-users.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'user_id', 'sessions', 'total' ),
				'properties'           => array(
					'user_id'  => array(
						'type'        => 'integer',
						'description' => __( 'The user whose sessions are listed.', 'abilities-catalog' ),
					),
					'sessions' => array(
						'type'        => 'array',
						'description' => __( 'The list of active login sessions, one row per device/login.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'expiration', 'login' ),
							'properties'           => array(
								'expiration' => array(
									'type'        => 'integer',
									'description' => __( 'When the session expires, as a Unix timestamp in UTC seconds.', 'abilities-catalog' ),
								),
								'login'      => array(
									'type'        => 'integer',
									'description' => __( 'When the session was created (login time), as a Unix timestamp in UTC seconds, or 0 if not recorded.', 'abilities-catalog' ),
								),
								'ip'         => array(
									'type'        => 'string',
									'description' => __( 'The IP address the session was created from, or an empty string if not recorded.', 'abilities-catalog' ),
								),
								'user_agent' => array(
									'type'        => 'string',
									'description' => __( 'The browser user-agent the session was created from, or an empty string if not recorded.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'    => array(
						'type'        => 'integer',
						'description' => __( 'The number of active sessions returned.', 'abilities-catalog' ),
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
	 * Coarse permission gate: the caller must be logged in.
	 *
	 * The object-level decision is deferred to `execute()`: reading the current
	 * user's own sessions is always allowed, while reading another user's
	 * sessions requires `edit_user` on that user. Doing the object-level check
	 * here would mask a missing or non-existent user as a generic permission
	 * denial, so `execute()` surfaces the specific `rest_user_invalid_id` (404)
	 * and `rest_forbidden` (403) instead.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool Whether the caller is logged in.
	 */
	public function hasPermission( $input = null ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by listing the target user's active sessions.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The user id, projected session rows, and total, or an error.
	 */
	public function execute( $input = null ) {
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
				__( 'Sorry, you are not allowed to list this user\'s sessions.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$sessions = array();
		foreach ( WP_Session_Tokens::get_instance( $uid )->get_all() as $session ) {
			$session    = (array) $session;
			$sessions[] = array(
				'expiration' => (int) ( $session['expiration'] ?? 0 ),
				'login'      => (int) ( $session['login'] ?? 0 ),
				'ip'         => (string) ( $session['ip'] ?? '' ),
				'user_agent' => (string) ( $session['ua'] ?? '' ),
			);
		}

		return array(
			'user_id'  => $uid,
			'sessions' => $sessions,
			'total'    => count( $sessions ),
		);
	}
}
