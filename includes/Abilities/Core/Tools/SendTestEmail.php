<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Tools;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Net-new write ability: `tools/send-test-email`.
 *
 * Sends ONE real test email so an agent can verify the site can deliver mail. Wraps
 * core `wp_mail()` with a fixed test subject and body; only the recipient is
 * configurable, defaulting to the site admin email (`get_option( 'admin_email' )`).
 * This is a diagnostics tool, not a general mailer — the subject/message are not
 * caller-controlled, which keeps the blast radius to a single message to one
 * admin-chosen address.
 *
 * Classification rationale:
 * - `readonly` is false: this performs an outward action (it sends an email). The
 *   boolean must still be declared, so it is present and set to false.
 * - `destructive` is false: nothing is persisted or mutated — the email is transient
 *   and there is no stored state to lose or reverse.
 * - `idempotent` is false: each call sends another email, so repeating it is NOT a
 *   no-op with the same end state.
 * - Not `dangerous`: the operation is bounded (one email to one admin-chosen
 *   recipient); the operational-risk tier (cron writes, cache flush) does not apply.
 *
 * No `meta.screen` is set: there is no dedicated wp-admin screen for a test send.
 *
 * Security note: core's `wp_mail()` performs NO capability check of its own. The
 * `permission_callback` plus the explicit `current_user_can( 'manage_options' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 * `manage_options` is the coarse guard because sending mail exercises site-wide mail
 * configuration.
 *
 * This is an all-optional ability: `to` carries a default of `''` and is resolved to
 * the admin email inside execute(). The schema deliberately omits `format: email` so
 * the empty-string default validates; the address is validated with `is_email()` in
 * execute() instead.
 *
 * @since 0.7.0
 */
final class SendTestEmail implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'tools/send-test-email';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Send Test Email', 'abilities-catalog' ),
			'description'         => __( 'Sends a real email — a single fixed-content test message — to verify the site can deliver mail. Use this to diagnose mail configuration after changing SMTP settings or installing a mail plugin. The subject and body are fixed (a configuration-test notice); only the recipient is configurable and defaults to the site admin email when omitted. Returns whether wp_mail accepted the message and the resolved recipient address. A sent value of true means WordPress handed the message to the mailer, not that it was delivered. This is not a general-purpose mailer. Requires the manage_options capability.', 'abilities-catalog' ),
			'category'            => 'tools',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'to' => array(
						'type'        => 'string',
						'default'     => '',
						'description' => __( 'Recipient email address; defaults to the site admin email when empty.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'sent', 'to' ),
				'properties'           => array(
					'sent' => array(
						'type'        => 'boolean',
						'description' => __( 'True if wp_mail accepted the message for sending. This is the success signal, but it does not guarantee delivery.', 'abilities-catalog' ),
					),
					'to'   => array(
						'type'        => 'string',
						'description' => __( 'The resolved recipient address the test email was sent to (the site admin email when no recipient was provided).', 'abilities-catalog' ),
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
					'idempotent'  => false,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Coarse permission gate: the caller must be able to manage options.
	 *
	 * This is the hard server-side guard. Sending mail exercises site-wide mail
	 * configuration, so `manage_options` is the baseline. Core's `wp_mail()` checks
	 * nothing, so this callback and the matching check at the top of
	 * {@see self::execute()} are the only authorization. The check is
	 * object-independent — there is no per-message capability in core.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may manage options.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by sending one fixed test email.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here, at
	 * the top and before any send, because the wrapped core function performs no
	 * capability check of its own. The recipient is resolved (admin email when empty)
	 * and validated with `is_email()` before `wp_mail()` is called.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,bool|string>|\WP_Error The send result, or a WP_Error.
	 */
	public function execute( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_options',
				__( 'You are not allowed to send a test email.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input = is_array( $input ) ? $input : array();

		// An omitted/blank recipient defaults to the site admin email; a recipient
		// that was actually supplied but is not a valid address is rejected (not
		// silently replaced with the admin email).
		$raw = isset( $input['to'] ) && is_string( $input['to'] ) ? trim( $input['to'] ) : '';
		$to  = '' === $raw ? (string) get_option( 'admin_email' ) : sanitize_email( $raw );

		if ( ! is_email( $to ) ) {
			return new WP_Error(
				'abilities_catalog_invalid_email',
				__( 'The recipient is not a valid email address.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Test email', 'abilities-catalog' ),
			$site_name
		);
		$message = sprintf(
			/* translators: %s: site name. */
			__( 'This is a test email from %s. Receiving it confirms your site can send email.', 'abilities-catalog' ),
			$site_name
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Sending one diagnostic test email to a manage_options user's chosen address is the explicit purpose of this ability, not bulk mailing.
		$sent = wp_mail( $to, $subject, $message );

		return array(
			'sent' => (bool) $sent,
			'to'   => $to,
		);
	}
}
