<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalog\Abilities\Core\SiteHealth;

use GalatanOvidiu\AbilitiesCatalog\Contracts\Ability;
use GalatanOvidiu\AbilitiesCatalog\Support\RestError;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * T1 read ability: `site-health/run-tests`.
 *
 * Wraps `GET /wp-site-health/v1/tests/<test>` via `rest_do_request()`. The core
 * Site Health REST controller exposes one route per test (for example
 * `background-updates`, `loopback-requests`, `https-status`,
 * `dotorg-communication`, `authorization-header`, `page-cache`). Some of those
 * tests perform live loopback or outbound HTTP requests; none mutate data, so the
 * ability stays read-only.
 *
 * @since 0.1.0
 */
final class RunTests implements Ability {

	/**
	 * Slugs of the Site Health tests exposed as individual REST routes.
	 *
	 * @var list<string>
	 */
	private const AVAILABLE_TESTS = array(
		'background-updates',
		'loopback-requests',
		'https-status',
		'dotorg-communication',
		'authorization-header',
		'page-cache',
	);

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'site-health/run-tests';
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array {
		return array(
			'label'               => __( 'Run Site Health Test', 'abilities-catalog' ),
			'description'         => __( 'Runs a single asynchronous Site Health test through the REST API and reports its result. Some tests perform live HTTP or loopback checks. The result carries a `status` of good, recommended, or critical.', 'abilities-catalog' ),
			'category'            => 'site-health',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'test' => array(
						'type'        => 'string',
						'enum'        => self::AVAILABLE_TESTS,
						'description' => __( 'The Site Health test slug to run. Must be one of the values listed in this field\'s enum.', 'abilities-catalog' ),
					),
				),
				'required'             => array( 'test' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'test', 'label', 'status' ),
				'properties'           => array(
					'test'        => array(
						'type'        => 'string',
						'description' => __( 'The slug of the test that was run.', 'abilities-catalog' ),
					),
					'label'       => array(
						'type'        => 'string',
						'description' => __( 'A human-readable label describing the test.', 'abilities-catalog' ),
					),
					'status'      => array(
						'type'        => 'string',
						'enum'        => array( 'good', 'recommended', 'critical' ),
						'description' => __( 'The test outcome: good, recommended, or critical.', 'abilities-catalog' ),
					),
					'badge'       => array(
						'type'                 => 'object',
						'description'          => __( 'The category this test is grouped in.', 'abilities-catalog' ),
						'properties'           => array(
							'label' => array(
								'type' => 'string',
							),
							'color' => array(
								'type' => 'string',
							),
						),
						'additionalProperties' => false,
					),
					'description' => array(
						'type'        => 'string',
						'description' => __( 'An explanation of what the test checks and why it matters. Contains HTML.', 'abilities-catalog' ),
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
	 * Permission check: the Site Health capability.
	 *
	 * This is a pure capability check. The validity of the `test` slug is enforced
	 * by the input-schema enum (rejected with a 400 before this runs) and by the
	 * explicit `site_health_unknown_test` guard in `execute()`, so a bad slug is
	 * never collapsed into a permission denial.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may run Site Health tests.
	 */
	public function hasPermission( $input ): bool {
		return current_user_can( 'view_site_health_checks' );
	}

	/**
	 * Executes the ability by dispatching the internal Site Health REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|\WP_Error The wrapped result, or the REST error.
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$test  = $this->normalizeTest( (string) ( $input['test'] ?? '' ) );

		if ( ! in_array( $test, self::AVAILABLE_TESTS, true ) ) {
			return new WP_Error(
				'site_health_unknown_test',
				__( 'The requested Site Health test is not available.', 'abilities-catalog' ),
				array( 'status' => 400 )
			);
		}

		$request  = new WP_REST_Request( 'GET', '/wp-site-health/v1/tests/' . $test );
		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return RestError::from( $response );
		}

		$data = rest_get_server()->response_to_data( $response, false );
		$data = is_array( $data ) ? $data : array();

		$result = array(
			'test'   => isset( $data['test'] ) ? (string) $data['test'] : $test,
			'label'  => isset( $data['label'] ) ? (string) $data['label'] : '',
			'status' => isset( $data['status'] ) ? (string) $data['status'] : '',
		);

		if ( isset( $data['badge'] ) && is_array( $data['badge'] ) ) {
			$result['badge'] = array(
				'label' => isset( $data['badge']['label'] ) ? (string) $data['badge']['label'] : '',
				'color' => isset( $data['badge']['color'] ) ? (string) $data['badge']['color'] : '',
			);
		}

		if ( isset( $data['description'] ) ) {
			$result['description'] = (string) $data['description'];
		}

		return $result;
	}

	/**
	 * Normalizes a test slug, keeping hyphens but dropping unexpected characters.
	 *
	 * @param string $test The raw test slug from input.
	 * @return string The normalized slug.
	 */
	private function normalizeTest( string $test ): string {
		return (string) preg_replace( '/[^a-z0-9-]/', '', strtolower( $test ) );
	}
}
