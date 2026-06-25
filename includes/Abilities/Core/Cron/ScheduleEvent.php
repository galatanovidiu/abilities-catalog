<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Cron;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `og-cron/schedule-event`.
 *
 * Schedules a new WP-Cron event — either recurring (when a `recurrence` schedule
 * name is supplied) or a one-off single event (when `recurrence` is omitted).
 * Recurring events wrap core `wp_schedule_event()`; one-off events wrap
 * `wp_schedule_single_event()`. Both are called with `$wp_error = true` so core
 * returns a `WP_Error` (not `false`) on failure, and the stored event is read back
 * with `wp_get_scheduled_event()` and projected into the shared cron event row.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it adds an event to the site-wide `cron`
 *   option).
 * - `destructive` is false: adding an event is reversible via
 *   `og-cron/unschedule-event` and does not, on its own, have a broad blast radius.
 * - `idempotent` is false: a second identical call is rejected with a 409 (the
 *   duplicate guard below), not treated as a same-state no-op.
 * - `dangerous` is true: scheduling mutates the site-wide `cron` option, which the
 *   generic option writer (`og-settings/update-option`) deliberately refuses. There is
 *   no `Support/` guard for cron (no filesystem/source/upgrader/option-allow-list
 *   risk class applies); the hard guard is `manage_options` plus the existence /
 *   duplicate pre-checks in {@see self::execute()}. The Registry auto-lists any
 *   `dangerous` ability in the `abilities_catalog_dangerous_tools` filter.
 *
 * No `meta.screen` is set: core has no dedicated wp-admin cron screen to deep-link
 * (the same reason the generic option pair omits `screen`).
 *
 * Security note: core's `wp_schedule_event()` / `wp_schedule_single_event()` perform
 * NO capability check of their own. The `permission_callback` plus the explicit
 * `current_user_can( 'manage_options' )` check at the top of {@see self::execute()}
 * are the only authorization guards.
 *
 * @since 0.6.0
 */
final class ScheduleEvent implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-cron/schedule-event';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Schedule Cron Event', 'abilities-catalog' ),
			'description'         => __( 'Schedules a new WP-Cron event (recurring or one-off), returning the stored event and a scheduled flag. Pass recurrence (a schedule name from og-cron/list-schedules, e.g. "hourly") for a recurring event; omit recurrence for a one-off single event that runs once at timestamp. This mutates the site-wide cron schedule and is reversible: remove it with og-cron/unschedule-event. Fails with a 409 if an event with the same hook and args already exists; unschedule that one first.', 'abilities-catalog' ),
			'category'            => 'og-core-cron',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'hook', 'timestamp' ),
				'properties'           => array(
					'hook'       => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The action hook to schedule. Any hook name is allowed; an event for a hook with no registered listener simply does nothing when it fires.', 'abilities-catalog' ),
					),
					'timestamp'  => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Unix UTC seconds for the first or only run. A timestamp in the past makes the event due on the next cron tick.', 'abilities-catalog' ),
					),
					'recurrence' => array(
						'type'        => 'string',
						'description' => __( 'Optional. A schedule name from og-cron/list-schedules (e.g. "hourly", "daily") to make the event recur. Omit for a one-off single event. Validated by WordPress against the registered schedules; an unknown name is rejected.', 'abilities-catalog' ),
					),
					'args'       => array(
						'type'        => 'array',
						'default'     => array(),
						'description' => __( 'Optional. Arguments passed to the hook callback; also part of the event identity (two events differing only in args are distinct). Pass these back verbatim to og-cron/unschedule-event. Defaults to an empty array.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'scheduled', 'hook', 'timestamp', 'gmt_date', 'schedule', 'interval', 'args' ),
				'properties'           => array(
					'scheduled' => array(
						'type'        => 'boolean',
						'description' => __( 'True once the event has been scheduled and read back. This is the success signal.', 'abilities-catalog' ),
					),
					'hook'      => array(
						'type'        => 'string',
						'description' => __( 'The action hook name the event was scheduled under.', 'abilities-catalog' ),
					),
					'timestamp' => array(
						'type'        => 'integer',
						'description' => __( 'Unix UTC seconds of this occurrence. This is the canonical time; gmt_date is a readability projection of it.', 'abilities-catalog' ),
					),
					'gmt_date'  => array(
						'type'        => 'string',
						'description' => __( 'The occurrence time as an ISO-8601 UTC string, derived from timestamp.', 'abilities-catalog' ),
					),
					'schedule'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The recurrence schedule name (e.g. "hourly"), or null for a one-off single event.', 'abilities-catalog' ),
					),
					'interval'  => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The recurrence interval in seconds, or null for a one-off single event.', 'abilities-catalog' ),
					),
					'args'      => array(
						'type'        => 'array',
						'description' => __( 'The arguments the event was scheduled with. Pass these back verbatim to og-cron/unschedule-event to remove it.', 'abilities-catalog' ),
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
					'idempotent'  => false,
					'dangerous'   => true,
				),
				'abilities_catalog' => array(
					'scope' => 'site',
				),
				'show_in_rest'      => true,
			),
		);
	}

	/**
	 * Coarse permission gate: the required input is present and the caller may
	 * manage options.
	 *
	 * This is the hard server-side guard. It is object-independent (no DB lookups):
	 * the duplicate and validity checks live in {@see self::execute()} so they
	 * surface specific errors instead of collapsing into a generic permission
	 * denial. `manage_options` is required because scheduling mutates the site-wide
	 * `cron` option, which the generic option writer refuses.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the call may proceed.
	 */
	public function hasPermission( $input = null ): bool {
		$input = is_array( $input ) ? $input : array();

		return isset( $input['hook'], $input['timestamp'] ) && current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by scheduling the cron event.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here, at
	 * the top and before any state branch, because the wrapped core functions perform
	 * no capability check of their own. A duplicate guard then refuses an event whose
	 * hook and args already match an existing event (core does NOT dedupe recurring
	 * events, so this prevents unbounded growth of the `cron` option).
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The scheduled event, or a WP_Error.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_cron',
				__( 'You are not allowed to schedule cron events.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$hook = isset( $input['hook'] ) ? (string) $input['hook'] : '';
		if ( '' === $hook ) {
			return new WP_Error(
				'abilities_catalog_invalid_hook',
				__( 'A non-empty hook name is required to schedule a cron event.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : 0;

		/*
		 * Core treats `$args` positionally (callback params; keys ignored), but stores
		 * and hashes it verbatim via `md5( serialize( $args ) )` for event identity.
		 * The input schema declares `args` as a JSON array, which always decodes to a
		 * list, so reindex any stray associative keys to a list. This keeps the value a
		 * `list<mixed>` (what core's signatures expect) without altering identity for the
		 * list input the schema guarantees; the read-back below returns the stored args
		 * verbatim for round-tripping to og-cron/unschedule-event.
		 *
		 * @var list<mixed> $args
		 */
		$args       = isset( $input['args'] ) && is_array( $input['args'] ) ? array_values( $input['args'] ) : array();
		$recurrence = isset( $input['recurrence'] ) ? (string) $input['recurrence'] : '';

		// Duplicate guard: an event with this hook and args already exists.
		if ( false !== wp_get_scheduled_event( $hook, $args ) ) {
			return new WP_Error(
				'abilities_catalog_cron_event_exists',
				__( 'An event with that hook and args is already scheduled; unschedule it first with og-cron/unschedule-event.', 'abilities-catalog' ),
				array( 'status' => 409 )
			);
		}

		$result = '' !== $recurrence
			? wp_schedule_event( $timestamp, $recurrence, $hook, $args, true )
			: wp_schedule_single_event( $timestamp, $hook, $args, true );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => $this->statusForScheduleError( (string) $result->get_error_code() ) )
			);
		}

		$event = wp_get_scheduled_event( $hook, $args, $timestamp );
		if ( false === $event ) {
			return new WP_Error(
				'abilities_catalog_cron_schedule_failed',
				__( 'The cron event was scheduled but could not be read back.', 'abilities-catalog' ),
				array( 'status' => 500 )
			);
		}

		$schedule = isset( $event->schedule ) && false !== $event->schedule ? (string) $event->schedule : null;
		$interval = isset( $event->interval ) ? (int) $event->interval : null;

		return array(
			'scheduled' => true,
			'hook'      => (string) $event->hook,
			'timestamp' => (int) $event->timestamp,
			'gmt_date'  => gmdate( 'c', (int) $event->timestamp ),
			'schedule'  => $schedule,
			'interval'  => $interval,
			'args'      => is_array( $event->args ) ? $event->args : array(),
		);
	}

	/**
	 * Maps a core cron-scheduling error code to a stable HTTP status.
	 *
	 * @param string $code The error code returned by the core scheduling function.
	 * @return int The HTTP status for the project error.
	 */
	private function statusForScheduleError( string $code ): int {
		switch ( $code ) {
			case 'invalid_timestamp':
			case 'invalid_schedule':
				return 400;
			case 'duplicate_event':
			case 'pre_schedule_event_false':
			case 'schedule_event_false':
				return 409;
			case 'could_not_set':
				return 500;
			default:
				return 400;
		}
	}
}
