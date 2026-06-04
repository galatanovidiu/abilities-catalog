<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\SiteHealth;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\AdminIncludes;
use WP_Error;
use WP_Site_Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `site-health/get-status`.
 *
 * Net-new. There is no single aggregate "status" REST route, so this ability
 * enumerates the registered Site Health tests via {@see WP_Site_Health::get_tests()}
 * and runs only the cheap `direct` tests in-process (each direct test resolves to a
 * `get_test_<name>()` method that returns a `{label,status,...}` array). The slower
 * `async` tests (loopback, HTTPS, dotorg communication) perform live HTTP and are
 * NOT run here — they are listed by identifier and label only.
 *
 * Each direct test is wrapped in a try/catch so one failing test cannot fatal the
 * whole ability; failing tests are skipped. A summary count of the direct results
 * by status is included.
 *
 * @since 0.1.0
 */
final class GetStatus implements Ability {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'site-health/get-status';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Get Site Health Status', 'abilities-catalog' ),
			'description'         => __( 'Runs the cheap direct Site Health tests and lists the asynchronous tests. Does not run live HTTP, loopback, or cron checks.', 'abilities-catalog' ),
			'category'            => 'site-health',
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array(),
				'properties'           => array(
					'direct'  => array(
						'type'        => 'array',
						'description' => __( 'Results of the direct tests that were run.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'test'   => array(
									'type'        => 'string',
									'description' => __( 'The test identifier.', 'abilities-catalog' ),
								),
								'label'  => array(
									'type'        => 'string',
									'description' => __( 'The human-readable test label.', 'abilities-catalog' ),
								),
								'status' => array(
									'type'        => 'string',
									'description' => __( 'The test outcome: good, recommended, or critical.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'async'   => array(
						'type'        => 'array',
						'description' => __( 'Asynchronous tests that were listed but not run.', 'abilities-catalog' ),
						'items'       => array(
							'type'                 => 'object',
							'properties'           => array(
								'test'  => array(
									'type'        => 'string',
									'description' => __( 'The test identifier.', 'abilities-catalog' ),
								),
								'label' => array(
									'type'        => 'string',
									'description' => __( 'The human-readable test label.', 'abilities-catalog' ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'summary' => array(
						'type'                 => 'object',
						'description'          => __( 'Count of direct results by status.', 'abilities-catalog' ),
						'properties'           => array(
							'good'        => array(
								'type'        => 'integer',
								'description' => __( 'Number of direct tests with status "good".', 'abilities-catalog' ),
							),
							'recommended' => array(
								'type'        => 'integer',
								'description' => __( 'Number of direct tests with status "recommended".', 'abilities-catalog' ),
							),
							'critical'    => array(
								'type'        => 'integer',
								'description' => __( 'Number of direct tests with status "critical".', 'abilities-catalog' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'hasPermission' ),
			'meta'                => array(
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
			),
		);
	}

	/**
	 * Permission check: the capability that gates the Site Health screen.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may view Site Health checks.
	 */
	public function hasPermission( $input = null ): bool {
		return current_user_can( 'view_site_health_checks' );
	}

	/**
	 * Executes the ability by running the direct tests and listing the async tests.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The status structure, or an error.
	 */
	public function execute( $input = null ) {
		AdminIncludes::load( 'class-wp-site-health' );

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return new WP_Error(
				'site_health_unavailable',
				__( 'Site Health is not available on this installation.', 'abilities-catalog' )
			);
		}

		$site_health = WP_Site_Health::get_instance();
		$tests       = $site_health->get_tests();

		$direct  = array();
		$summary = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);

		foreach ( ( $tests['direct'] ?? array() ) as $key => $test ) {
			$identifier = is_string( $test['test'] ?? null ) ? $test['test'] : (string) $key;
			$result     = $this->runDirectTest( $site_health, $test );

			if ( null === $result ) {
				continue;
			}

			$status = (string) ( $result['status'] ?? '' );

			$direct[] = array(
				'test'   => (string) ( $result['test'] ?? $identifier ),
				'label'  => (string) ( $result['label'] ?? ( $test['label'] ?? $identifier ) ),
				'status' => $status,
			);

			if ( ! isset( $summary[ $status ] ) ) {
				continue;
			}

			++$summary[ $status ];
		}

		$async = array();
		foreach ( ( $tests['async'] ?? array() ) as $key => $test ) {
			$async[] = array(
				'test'  => (string) $key,
				'label' => (string) ( $test['label'] ?? $key ),
			);
		}

		return array(
			'direct'  => $direct,
			'async'   => $async,
			'summary' => $summary,
		);
	}

	/**
	 * Runs a single direct test defensively.
	 *
	 * Resolves the test callback the same way core does — a string `test` maps to a
	 * `get_test_<name>()` method on the instance, otherwise the value is treated as a
	 * callable. Any throwable from the callback is swallowed so a single broken test
	 * does not abort the whole ability.
	 *
	 * @param \WP_Site_Health      $site_health The Site Health instance.
	 * @param array<string,mixed> $test        A single entry from the `direct` test list.
	 * @return array<string,mixed>|null The test result array, or null when it could not run.
	 */
	private function runDirectTest( WP_Site_Health $site_health, array $test ): ?array {
		$callback = null;
		$name     = $test['test'] ?? null;

		if ( is_string( $name ) ) {
			$method = 'get_test_' . $name;
			if ( method_exists( $site_health, $method ) && is_callable( array( $site_health, $method ) ) ) {
				$callback = array( $site_health, $method );
			}
		}

		if ( null === $callback && isset( $test['test'] ) && is_callable( $test['test'] ) ) {
			$callback = $test['test'];
		}

		if ( null === $callback ) {
			return null;
		}

		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $e ) {
			return null;
		}

		return is_array( $result ) ? $result : null;
	}
}
