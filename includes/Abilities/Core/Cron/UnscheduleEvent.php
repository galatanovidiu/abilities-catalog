<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Cron;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dangerous-tier write ability: `cron/unschedule-event`.
 *
 * Removes ONE scheduled WP-Cron event occurrence, identified by its exact hook +
 * timestamp + args, by wrapping core `wp_unschedule_event()`. Narrow by design: it
 * removes a single occurrence, not every event for a hook (the widest-blast
 * `wp_unschedule_hook()` is deliberately not exposed).
 *
 * Existence pre-check (the reason execute() reads before it removes): core's
 * `wp_unschedule_event()` `unset()`s the event and then calls
 * `update_option( 'cron', ... )`, and `update_option()` returns false when the
 * stored value is unchanged. Unscheduling a non-existent event therefore leaves the
 * cron array unchanged and (with `$wp_error = true`) returns a misleading
 * `could_not_set` error rather than a 404. So execute() first calls
 * `wp_get_scheduled_event()`; if that returns false it returns a specific 404, and
 * only then calls `wp_unschedule_event()` — at which point the array genuinely
 * changes and the save succeeds.
 *
 * Classification rationale:
 * - `readonly` is false: this is a write (it removes a scheduled event).
 * - `destructive` is true: removing a core maintenance event (such as
 *   `wp_version_check`, `wp_update_plugins`, or `wp_scheduled_delete`) disables that
 *   automation site-wide until it is re-scheduled — broad blast radius, delete-like.
 * - `idempotent` is false: a second identical call hits the existence pre-check and
 *   returns a 404, not a same-state no-op.
 * - `dangerous` is true: cron-schedule mutation is too sensitive for the generic
 *   option writer (the `cron` option is deliberately off `OptionAllowList`). Cron has
 *   no filesystem/source/upgrader risk class, so there is no `Support/` guard; the
 *   hard guard is `current_user_can( 'manage_options' )` plus the existence pre-check.
 *
 * No `meta.screen` is set: core has no dedicated cron admin screen (the same reason
 * the generic option pair omits `screen`).
 *
 * Security note: `wp_unschedule_event()` performs NO capability check of its own. The
 * `permission_callback` plus the explicit `current_user_can( 'manage_options' )`
 * check at the top of {@see self::execute()} are the only authorization guards.
 *
 * @since 0.6.0
 */
final class UnscheduleEvent implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'cron/unschedule-event';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Unschedule Event', 'abilities-catalog' ),
			'description'         => __( 'Removes one scheduled WP-Cron event by its exact hook, timestamp, and args. Unscheduling a core maintenance event (such as wp_version_check, wp_update_plugins, or wp_scheduled_delete) disables that automation until it is re-scheduled. Reversible: re-create it with cron/schedule-event using the returned previous_schedule. Removes only the single matching occurrence, not every event for the hook. Identify the event first with cron/list-events or cron/get-event; if no event matches the hook+timestamp+args this returns a 404 (abilities_catalog_cron_event_not_found).', 'abilities-catalog' ),
			'category'            => 'cron',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'hook', 'timestamp' ),
				'properties'           => array(
					'hook'      => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'The action hook name of the event to remove. Get it from cron/list-events or cron/get-event.', 'abilities-catalog' ),
					),
					'timestamp' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The exact Unix UTC seconds of the occurrence to remove, taken verbatim from cron/list-events or cron/get-event. Only the event at this exact timestamp is removed.', 'abilities-catalog' ),
					),
					'args'      => array(
						'type'        => 'array',
						'default'     => array(),
						'description' => __( 'The arguments the event was scheduled with; they uniquely identify the event and must match exactly. Copy them verbatim from cron/list-events or cron/get-event. Omit for an event scheduled with no args.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'unscheduled', 'hook', 'timestamp', 'gmt_date', 'previous_schedule', 'previous_interval', 'args' ),
				'properties'           => array(
					'unscheduled'       => array(
						'type'        => 'boolean',
						'description' => __( 'True when the matching event was removed. This is the success signal.', 'abilities-catalog' ),
					),
					'hook'              => array(
						'type'        => 'string',
						'description' => __( 'The action hook name of the removed event, echoed back.', 'abilities-catalog' ),
					),
					'timestamp'         => array(
						'type'        => 'integer',
						'description' => __( 'The Unix UTC seconds of the removed occurrence, echoed back.', 'abilities-catalog' ),
					),
					'gmt_date'          => array(
						'type'        => 'string',
						'description' => __( 'The removed occurrence time as an ISO-8601 UTC string (for readability; timestamp is canonical).', 'abilities-catalog' ),
					),
					'previous_schedule' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The recurrence name the removed event had (e.g. "hourly", "daily"), or null if it was a one-off single event. Pass this back as the recurrence of cron/schedule-event to re-create the event.', 'abilities-catalog' ),
					),
					'previous_interval' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The recurrence interval in seconds the removed event had, or null if it was a one-off single event.', 'abilities-catalog' ),
					),
					'args'              => array(
						'type'        => 'array',
						'description' => __( 'The arguments the removed event was scheduled with, echoed back. Pass them verbatim to cron/schedule-event to re-create the event.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'       => array(
					'readonly'    => false,
					'destructive' => true,
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
	 * Coarse, object-independent permission gate: the caller must supply the
	 * identifying fields and be able to manage options.
	 *
	 * `manage_options` is the hard server-side guard: unscheduling mutates the
	 * site-wide `cron` option, which the generic option writer refuses to touch.
	 * The check is intentionally object-independent (no cron-array lookup) so the
	 * existence pre-check in {@see self::execute()} can surface a specific 404
	 * instead of the Abilities API collapsing a missing event into a generic
	 * permission failure.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the required inputs are present and the user may manage options.
	 */
	public function hasPermission( $input ): bool {
		return is_array( $input )
			&& isset( $input['hook'], $input['timestamp'] )
			&& current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by removing the matching scheduled event.
	 *
	 * The explicit `current_user_can( 'manage_options' )` check is repeated here, at
	 * the top and before any state branch, because `wp_unschedule_event()` performs
	 * no capability check of its own. The existence pre-check then runs before the
	 * removal so a non-existent event returns a 404 rather than the misleading
	 * `could_not_set` error described in the class PHPDoc.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The removed event projection, or a WP_Error.
	 */
	public function execute( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'abilities_catalog_cannot_manage_cron',
				__( 'You are not allowed to unschedule cron events.', 'abilities-catalog' ),
				array( 'status' => 403 )
			);
		}

		$input     = is_array( $input ) ? $input : array();
		$hook      = isset( $input['hook'] ) ? (string) $input['hook'] : '';
		$timestamp = isset( $input['timestamp'] ) ? (int) $input['timestamp'] : 0;
		$args      = isset( $input['args'] ) && is_array( $input['args'] ) ? array_values( $input['args'] ) : array();

		// Existence pre-check: read the event BEFORE removing it. Without this, an
		// absent event leaves the cron array unchanged and wp_unschedule_event()
		// returns a misleading `could_not_set` save error rather than a 404.
		$event = wp_get_scheduled_event( $hook, $args, $timestamp );
		if ( false === $event ) {
			return new WP_Error(
				'abilities_catalog_cron_event_not_found',
				__( 'No scheduled event matches that hook, timestamp, and args.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		// Capture the recurrence state being destroyed so the agent can undo via
		// cron/schedule-event. Core stores schedule=false for a one-off single event
		// (normalize to null), and only sets interval on a recurring event.
		$previous_schedule = isset( $event->schedule ) && false !== $event->schedule
			? (string) $event->schedule
			: null;
		$previous_interval = isset( $event->interval ) ? (int) $event->interval : null;

		$result = wp_unschedule_event( $timestamp, $hook, $args, true );
		if ( is_wp_error( $result ) ) {
			// The array genuinely changed here, so `could_not_set` would be a real
			// save failure (not the misleading absent-event case guarded above).
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return array(
			'unscheduled'       => true,
			'hook'              => $hook,
			'timestamp'         => $timestamp,
			'gmt_date'          => gmdate( 'c', $timestamp ),
			'previous_schedule' => $previous_schedule,
			'previous_interval' => $previous_interval,
			'args'              => $args,
		);
	}
}
