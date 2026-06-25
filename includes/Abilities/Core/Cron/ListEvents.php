<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Cron;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `og-cron/list-events`.
 *
 * Lists every scheduled WP-Cron event on the site, one flat row per occurrence
 * (a distinct timestamp + hook + args combination), so an agent can audit what
 * automation is scheduled and recover the exact hook/timestamp/args the other
 * cron abilities require as input.
 *
 * Built on `_get_cron_array()`, the private core accessor WP-CLI's
 * `wp cron event list` uses and the only way to read the full event list: core
 * exposes no REST route for WP-Cron. The function lives in wp-includes/cron.php
 * (always loaded), so no wp-admin includes are needed. It returns a nested map
 * `timestamp => hook => md5key => { schedule, args, interval? }`; this flattens
 * it to one row per leaf.
 *
 * Projection notes (kept in sync with the inline item schema):
 * - Core stores `schedule => false` for a one-off single event; this normalizes
 *   `false` to `null`.
 * - `interval` is only present on recurring events; it defaults to `null` when
 *   absent.
 * - `args` is the event's identity (core re-hashes it with `md5( serialize() )`),
 *   so it is passed through verbatim for the agent to re-supply to get/unschedule.
 *
 * @since 0.1.0
 */
final class ListEvents implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-cron/list-events';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Scheduled Events', 'abilities-catalog' ),
			'description'         => __( 'Lists every scheduled WP-Cron event, one row per occurrence, each with its hook name, timestamp (Unix UTC seconds), gmt_date, schedule, interval, and args. Use this to audit scheduled automation and to recover the exact hook/timestamp/args needed by og-cron/get-event, og-cron/schedule-event, and og-cron/unschedule-event. A one-off single event reports schedule and interval as null. An empty result means nothing is scheduled.', 'abilities-catalog' ),
			'category'            => 'og-core-cron',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'events', 'total' ),
				'properties'           => array(
					'events' => array(
						'type'        => 'array',
						'description' => __( 'All scheduled events, one row per occurrence. Use og-cron/get-event for a single event.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'hook', 'timestamp', 'gmt_date', 'schedule', 'interval', 'args' ),
							'properties'           => array(
								'hook'      => array(
									'type'        => 'string',
									'description' => __( 'The action hook name that fires when the event runs (e.g. "wp_version_check").', 'abilities-catalog' ),
								),
								'timestamp' => array(
									'type'        => 'integer',
									'description' => __( 'Unix UTC seconds of this occurrence. This is the canonical time value; pass it to og-cron/get-event or og-cron/unschedule-event.', 'abilities-catalog' ),
								),
								'gmt_date'  => array(
									'type'        => 'string',
									'description' => __( 'The timestamp as an ISO-8601 UTC date-time string, for readability only; the canonical value is timestamp.', 'abilities-catalog' ),
								),
								'schedule'  => array(
									'type'        => array( 'string', 'null' ),
									'description' => __( 'The recurrence name (e.g. "hourly", "daily"), or null for a one-off single event.', 'abilities-catalog' ),
								),
								'interval'  => array(
									'type'        => array( 'integer', 'null' ),
									'description' => __( 'The recurrence interval in seconds, or null for a one-off single event.', 'abilities-catalog' ),
								),
								'args'      => array(
									'type'        => 'array',
									'description' => __( 'The arguments the event was scheduled with (passed to the hook callback). These also identify the event: pass them back verbatim to og-cron/get-event or og-cron/unschedule-event. Empty for an event scheduled with no args.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'  => array(
						'type'        => 'integer',
						'description' => __( 'The number of event occurrences returned (the length of the events array).', 'abilities-catalog' ),
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
	 * Permission check: the current user may manage the site (`manage_options`).
	 *
	 * Core exposes WP-Cron through no REST route; the only equivalents are
	 * WP-CLI's `wp cron event list` and the Site Health "Scheduled events" info,
	 * both gated to administrators (CLI / `manage_options`), so `manage_options`
	 * is not weaker than core for this data. Event args can carry plugin-stored
	 * secrets, so this read is gated at the same strength as the connector reads
	 * rather than a lighter read capability.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by flattening the cron array into projected rows.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The events list and total count.
	 */
	public function execute( $input = null ) {
		$crons = _get_cron_array();

		$events = array();

		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			$timestamp = (int) $timestamp;

			foreach ( $hooks as $hook => $occurrences ) {
				if ( ! is_array( $occurrences ) ) {
					continue;
				}

				foreach ( $occurrences as $event ) {
					$schedule = isset( $event['schedule'] ) && false !== $event['schedule']
						? (string) $event['schedule']
						: null;
					$interval = isset( $event['interval'] ) ? (int) $event['interval'] : null;
					$args     = isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();

					$events[] = array(
						'hook'      => (string) $hook,
						'timestamp' => $timestamp,
						'gmt_date'  => gmdate( 'c', $timestamp ),
						'schedule'  => $schedule,
						'interval'  => $interval,
						'args'      => $args,
					);
				}
			}
		}

		return array(
			'events' => $events,
			'total'  => count( $events ),
		);
	}
}
