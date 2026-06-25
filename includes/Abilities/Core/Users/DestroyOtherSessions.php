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
 * Write ability: `og-users/destroy-other-sessions`.
 *
 * Ends the CURRENT user's login sessions on all OTHER devices, keeping the
 * caller's own session ("log out everywhere else"). Wraps core
 * `WP_Session_Tokens::destroy_others()` with the current session token from
 * `wp_get_session_token()`. Operates on the current user only: the token to
 * keep exists solely for the caller's own cookie login, so there is no
 * cross-user form of this operation.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it removes stored session tokens).
 * - `destructive` is false: a session token is not a source of truth — a logged
 *   out device can simply log back in to obtain a new session — and the caller's
 *   own session is deliberately preserved, so the blast radius is bounded and
 *   nothing is irreversibly lost.
 * - `idempotent` is true: once the other sessions are gone, running it again is
 *   a no-op that leaves the same end state (only the caller's session remains).
 *
 * Not `dangerous`: the caller keeps their own session and only the caller's own
 * other devices are affected (compare `og-users/destroy-all-sessions`, which can
 * end every session, including the caller's, and is dangerous).
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for ending the
 * current user's other sessions (the profile screen exposes the button but is
 * not addressable per-action), so there is nothing for a consumer to deep-link.
 *
 * Security note: `WP_Session_Tokens::destroy_others()` performs NO capability
 * check of its own. The `permission_callback` (a logged-in check) is the
 * authorization guard; because the operation is inherently self-scoped — it
 * targets `get_current_user_id()` and keeps `wp_get_session_token()` — any
 * logged-in caller is allowed to manage their own sessions.
 *
 * @since 0.8.0
 */
final class DestroyOtherSessions implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-users/destroy-other-sessions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Destroy Other Sessions', 'abilities-catalog' ),
			'description'         => __( 'Ends the current user\'s login sessions on all other devices, keeping this session logged in ("log out everywhere else"). Operates on the current user only; it does not accept a user ID and cannot log out another user (use og-users/destroy-all-sessions for an admin force-logout). Sessions are not a source of truth, so a logged-out device can simply sign back in. Requires an interactive cookie login: in an application-password or other non-cookie context there is no current session to keep and the call returns abilities_catalog_no_session (400).', 'abilities-catalog' ),
			'category'            => 'users',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'destroyed', 'remaining' ),
				'properties'           => array(
					'destroyed' => array(
						'type'        => 'boolean',
						'description' => __( 'Always true on success: the current user\'s other sessions were ended.', 'abilities-catalog' ),
					),
					'remaining' => array(
						'type'        => 'integer',
						'description' => __( 'The number of active sessions left for the current user after the operation, read back from core. Normally 1 — the caller\'s own (kept) session.', 'abilities-catalog' ),
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
	 * The operation is inherently self-scoped — it acts on
	 * `get_current_user_id()` and keeps the caller's own session token — so any
	 * logged-in user may manage their own sessions and no further capability is
	 * required. Core's `destroy_others()` checks nothing, so this callback (plus
	 * the empty-token guard in {@see self::execute()}) is the only authorization.
	 *
	 * The `$input` parameter defaults to null because the Abilities API invokes
	 * this with zero arguments for a no-input ability.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return bool True if the current user is logged in.
	 */
	public function hasPermission( $input = null ): bool {
		return is_user_logged_in();
	}

	/**
	 * Executes the ability by ending the current user's other sessions.
	 *
	 * Requires a current session token (`wp_get_session_token()`) to identify the
	 * session to keep. In a non-cookie context (e.g. an application-password
	 * request) there is no such token, so the call is rejected with
	 * `abilities_catalog_no_session` (400) rather than silently destroying every
	 * session.
	 *
	 * The `$input` parameter defaults to null because the Abilities API invokes
	 * this with zero arguments for a no-input ability.
	 *
	 * @param mixed $input The validated input data (unused; no-input ability).
	 * @return array<string,mixed>|\WP_Error The result, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return new WP_Error(
				'abilities_catalog_no_session',
				__( 'No interactive session to keep; this requires a cookie login.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$manager = WP_Session_Tokens::get_instance( get_current_user_id() );
		$manager->destroy_others( $token );

		return array(
			'destroyed' => true,
			'remaining' => count( $manager->get_all() ),
		);
	}
}
