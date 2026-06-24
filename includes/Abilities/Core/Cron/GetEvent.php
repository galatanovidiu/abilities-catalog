<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Cron;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core-function T1 read ability: `cron/get-event`.
 *
 * Returns a single scheduled WP-Cron event by its action hook (plus the exact
 * args it was scheduled with, plus an optional specific timestamp), projected
 * into the same flat closed row `cron/list-events` emits. This is the
 * single-object companion to `cron/list-events`. Built on the core accessor
 * `wp_get_scheduled_event()` (wp-includes/cron.php, loaded always) since core
 * exposes no REST route for cron; no wp-admin includes are loaded.
 *
 * Core stores `schedule => false` for a one-off single event and omits
 * `interval` on one-off events, so the projection normalizes `false` to null
 * and defaults `interval` to null. The `args` array is the event's identity
 * (core re-hashes it with `md5( serialize( $args ) )`), so a caller must pass
 * back the args verbatim — a near-miss args value resolves to a different
 * event or none. With no timestamp, core returns the next occurrence.
 *
 * @since 0.1.0
 */
final class GetEvent implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'cron/get-event';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Event', 'abilities-catalog' ),
			'description'         => __( 'Returns a single scheduled WP-Cron event by its hook, including its next timestamp, recurrence schedule, interval, and the args it was scheduled with. Single-event read; use cron/list-events to enumerate every event and discover the hook, timestamp, and args. Pass args back exactly as listed (they identify the event), and a specific timestamp to target one occurrence; omit timestamp to get the next occurrence.', 'abilities-catalog' ),
			'category'            => 'cron',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'hook' ),
				'properties'           => array(
					'hook'      => array(
						'type'        => 'string',
						'description' => __( 'The action hook name of the scheduled event. Discover hooks with cron/list-events.', 'abilities-catalog' ),
					),
					'args'      => array(
						'type'        => 'array',
						'description' => __( 'The arguments the event was scheduled with; they uniquely identify the event and must match exactly. Copy this verbatim from cron/list-events. Omit (defaults to an empty array) for an event scheduled with no args.', 'abilities-catalog' ),
						'default'     => array(),
					),
					'timestamp' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Unix UTC seconds of a specific occurrence to fetch (the timestamp from cron/list-events). Omit to get the next scheduled occurrence of this hook and args.', 'abilities-catalog' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'hook', 'timestamp', 'gmt_date', 'schedule', 'interval', 'args' ),
				'properties'           => array(
					'hook'      => array(
						'type'        => 'string',
						'description' => __( 'The action hook name of the scheduled event.', 'abilities-catalog' ),
					),
					'timestamp' => array(
						'type'        => 'integer',
						'description' => __( 'Unix UTC seconds of this occurrence. This is the canonical time; pass it back to cron/get-event or cron/unschedule-event to target this exact occurrence.', 'abilities-catalog' ),
					),
					'gmt_date'  => array(
						'type'        => 'string',
						'description' => __( 'The same occurrence as an ISO-8601 UTC date-time string (e.g. 2024-01-31T13:45:00+00:00), for readability. The timestamp field is canonical.', 'abilities-catalog' ),
					),
					'schedule'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The recurrence schedule name (e.g. "hourly", "daily"), or null for a one-off single event that runs once and is then removed.', 'abilities-catalog' ),
					),
					'interval'  => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'How often the event recurs, in seconds, or null for a one-off single event.', 'abilities-catalog' ),
					),
					'args'      => array(
						'type'        => 'array',
						'description' => __( 'The arguments the event was scheduled with. Pass this back verbatim to cron/get-event or cron/unschedule-event; it identifies the event.', 'abilities-catalog' ),
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
	 * Permission check: the current user may manage options (`manage_options`).
	 *
	 * Core exposes WP-Cron through no REST route; the equivalent read surfaces
	 * (WP-CLI `wp cron event list`, the Site Health "Scheduled events" info) are
	 * CLI/admin-gated, so `manage_options` is not weaker than core for this
	 * data. Event args can carry plugin-stored secrets, mirroring why connector
	 * reads also gate on `manage_options`. The guard is object-independent; an
	 * event that does not exist surfaces as the specific 404 from execute(),
	 * never as a permission denial. The hook name is not a secret, so it may
	 * appear in that error message.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by reading one scheduled event and projecting it.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The event row, or a 404 if no event matches.
	 */
	public function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		$hook = (string) ( $input['hook'] ?? '' );

		// Core treats `$args` positionally and hashes it verbatim for event identity.
		// The input schema declares a JSON array (always a list), so reindex any stray
		// associative keys to a list — the shape core's signature expects — without
		// altering identity for the list input the schema guarantees.
		$args = isset( $input['args'] ) && is_array( $input['args'] ) ? array_values( $input['args'] ) : array();

		$timestamp = null;
		if ( isset( $input['timestamp'] ) ) {
			$timestamp = (int) $input['timestamp'];
		}

		$event = wp_get_scheduled_event( $hook, $args, $timestamp );

		if ( false === $event ) {
			return new WP_Error(
				'abilities_catalog_cron_event_not_found',
				__( 'No scheduled event matches that hook, timestamp, and args.', 'abilities-catalog' ),
				array( 'status' => 404 )
			);
		}

		$ts       = (int) $event->timestamp;
		$schedule = isset( $event->schedule ) && false !== $event->schedule
			? (string) $event->schedule
			: null;
		$interval = isset( $event->interval ) ? (int) $event->interval : null;

		return array(
			'hook'      => (string) $event->hook,
			'timestamp' => $ts,
			'gmt_date'  => gmdate( 'c', $ts ),
			'schedule'  => $schedule,
			'interval'  => $interval,
			'args'      => is_array( $event->args ) ? $event->args : array(),
		);
	}
}
