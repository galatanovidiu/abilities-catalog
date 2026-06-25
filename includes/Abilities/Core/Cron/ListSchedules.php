<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\Cron;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core-function read ability: `og-cron/list-schedules`.
 *
 * Lists the registered WP-Cron recurrence schedules — the valid `recurrence`
 * values for `og-cron/schedule-event` — each with its display name and interval in
 * seconds. Wraps `wp_get_schedules()` (wp-includes/cron.php), which returns the
 * four core schedules (`hourly`, `twicedaily`, `daily`, `weekly`) plus any added
 * by plugins through the `cron_schedules` filter, keyed by schedule name with an
 * `{ interval, display }` value. All cron functions live in wp-includes, so no
 * wp-admin includes are loaded.
 *
 * The schedule list is not itself sensitive, but it is gated on `manage_options`
 * to stay consistent with the rest of the cron cluster (it pairs with
 * `og-cron/schedule-event`, which requires `manage_options`).
 *
 * @since 0.1.0
 */
final class ListSchedules implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'og-cron/list-schedules';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'List Schedules', 'abilities-catalog' ),
			'description'         => __( 'Lists the registered WP-Cron recurrence schedules — the valid recurrence values for og-cron/schedule-event — each with its display name and interval in seconds. Always returns at least the four core schedules (hourly, twicedaily, daily, weekly); plugins can add more.', 'abilities-catalog' ),
			'category'            => 'cron',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'schedules', 'total' ),
				'properties'           => array(
					'schedules' => array(
						'type'        => 'array',
						'description' => __( 'All registered recurrence schedules, one row per schedule.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'required'             => array( 'name', 'display', 'interval' ),
							'properties'           => array(
								'name'     => array(
									'type'        => 'string',
									'description' => __( 'The schedule identifier to pass as the recurrence of og-cron/schedule-event (e.g. "hourly", "daily").', 'abilities-catalog' ),
								),
								'display'  => array(
									'type'        => 'string',
									'description' => __( 'The human-readable label (e.g. "Once Hourly").', 'abilities-catalog' ),
								),
								'interval' => array(
									'type'        => 'integer',
									'description' => __( 'How often the schedule recurs, in seconds.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'total'     => array(
						'type'        => 'integer',
						'description' => __( 'The number of registered schedules returned (the length of the schedules array).', 'abilities-catalog' ),
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
	 * The schedule list itself is not sensitive, but it is gated on
	 * `manage_options` for consistency with the rest of the cron cluster: this
	 * read pairs with `og-cron/schedule-event`, which requires `manage_options`
	 * because scheduling mutates the site-wide `cron` option. Keeping the read
	 * at the same baseline means an agent that can list schedules can also act
	 * on them.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user can manage options.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Executes the ability by projecting the registered cron schedules.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed> The schedules list and total count.
	 */
	public function execute( $input = null ) {
		$schedules = array();

		foreach ( wp_get_schedules() as $name => $schedule ) {
			$schedules[] = array(
				'name'     => (string) $name,
				'display'  => (string) ( $schedule['display'] ?? '' ),
				'interval' => (int) ( $schedule['interval'] ?? 0 ),
			);
		}

		return array(
			'schedules' => $schedules,
			'total'     => count( $schedules ),
		);
	}
}
