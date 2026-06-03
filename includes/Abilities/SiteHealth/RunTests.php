<?php

declare(strict_types=1);

namespace Automattic\AbilitiesCatalog\Abilities\SiteHealth;

use Automattic\AbilitiesCatalog\Contracts\Ability;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
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
final class RunTests implements Ability
{
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
	public function name(): string
	{
		return 'site-health/run-tests';
	}

	/**
	 * {@inheritDoc}
	 */
	public function category(): array
	{
		return array(
			'slug'        => 'site-health',
			'label'       => __('Site Health', 'abilities-catalog'),
			'description' => __('Abilities that read Site Health status, tests, and debug information.', 'abilities-catalog'),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function args(): array
	{
		return array(
			'label'               => __('Run Site Health Test', 'abilities-catalog'),
			'description'         => __('Runs a single asynchronous Site Health test through the REST API. Some tests perform live HTTP or loopback checks.', 'abilities-catalog'),
			'category'            => 'site-health',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'test' => array(
						'type'        => 'string',
						'enum'        => self::AVAILABLE_TESTS,
						'description' => __('The Site Health test slug to run.', 'abilities-catalog'),
					),
				),
				'required'             => array('test'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array('result'),
				'properties'           => array(
					'result' => array(
						'type'                 => 'object',
						'description'          => __('The raw Site Health REST response for the requested test.', 'abilities-catalog'),
						'additionalProperties' => true,
					),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'hasPermission'),
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
	 * Permission check: the Site Health capability plus a valid, known test slug.
	 *
	 * Returns false when the `test` parameter is missing or not one of the routes
	 * exposed by core, so an unknown test never reaches the REST dispatcher.
	 *
	 * @param mixed $input The validated input data.
	 * @return bool True if the current user may run the requested test.
	 */
	public function hasPermission($input): bool
	{
		if (!current_user_can('view_site_health_checks')) {
			return false;
		}

		$input = is_array($input) ? $input : array();
		$test  = isset($input['test']) ? $this->normalizeTest((string) $input['test']) : '';

		return in_array($test, self::AVAILABLE_TESTS, true);
	}

	/**
	 * Executes the ability by dispatching the internal Site Health REST request.
	 *
	 * @param mixed $input The validated input data.
	 * @return array<string,mixed>|WP_Error The wrapped result, or the REST error.
	 */
	public function execute($input)
	{
		$input = is_array($input) ? $input : array();
		$test  = $this->normalizeTest((string) ($input['test'] ?? ''));

		if (!in_array($test, self::AVAILABLE_TESTS, true)) {
			return new WP_Error(
				'site_health_unknown_test',
				__('The requested Site Health test is not available.', 'abilities-catalog'),
				array('status' => 400)
			);
		}

		$request  = new WP_REST_Request('GET', '/wp-site-health/v1/tests/' . $test);
		$response = rest_do_request($request);

		if ($response->is_error()) {
			return $response->as_error();
		}

		$data = rest_get_server()->response_to_data($response, false);

		return array(
			'result' => is_array($data) ? $data : array(),
		);
	}

	/**
	 * Normalizes a test slug, keeping hyphens but dropping unexpected characters.
	 *
	 * @param string $test The raw test slug from input.
	 * @return string The normalized slug.
	 */
	private function normalizeTest(string $test): string
	{
		return (string) preg_replace('/[^a-z0-9-]/', '', strtolower($test));
	}
}
